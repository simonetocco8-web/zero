<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminMarketplaceRecords
{
    public const TITLES = ['orders' => 'Ordini', 'vendors' => 'Sottoordini', 'exchanges' => 'Scambi', 'payments' => 'Pagamenti', 'refunds' => 'Rimborsi', 'commissions' => 'Commissioni', 'reservations' => 'Stock reservations'];

    public function query(string $resource, array $f = [])
    {
        $q = match ($resource) {
            'orders' => DB::table('orders')->selectRaw("id,status,total_cents as amount_cents,currency,NULL as retailer_id,NULL as retailer_b_id,user_id as customer_id,id as resource_id,'order' as source,created_at"),
            'vendors' => DB::table('vendor_orders as v')->join('orders as o', 'o.id', '=', 'v.order_id')->selectRaw("v.id,v.status,v.total_cents as amount_cents,v.currency,v.retailer_id,NULL as retailer_b_id,o.user_id as customer_id,v.id as resource_id,'vendor' as source,v.created_at"),
            'exchanges' => DB::table('exchanges as e')->join('exchange_versions as v', 'v.id', '=', 'e.current_version_id')->selectRaw("e.id,e.status,v.difference_cents as amount_cents,v.currency,e.retailer_a_id as retailer_id,e.retailer_b_id,NULL as customer_id,e.id as resource_id,'exchange' as source,e.created_at"),
            'payments' => $this->payments(),
            'refunds' => DB::table('marketplace_refunds as r')->join('vendor_orders as v', 'v.id', '=', 'r.vendor_order_id')->join('orders as o', 'o.id', '=', 'v.order_id')->selectRaw("r.id,r.status,r.amount_cents,r.currency,v.retailer_id,NULL as retailer_b_id,o.user_id as customer_id,v.id as resource_id,'refund' as source,r.created_at"),
            'commissions' => $this->commissions(),
            'reservations' => DB::table('stock_reservations as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->selectRaw("s.id,s.status,NULL as amount_cents,'EUR' as currency,i.retailer_id,NULL as retailer_b_id,s.user_id as customer_id,COALESCE(s.order_id,s.exchange_id) as resource_id,CASE WHEN s.order_id IS NOT NULL THEN 'order_reservation' WHEN s.exchange_id IS NOT NULL THEN 'exchange_reservation' ELSE 'reservation' END as source,s.created_at"),
        };
        $records = DB::query()->fromSub($q, 'records');
        if (! empty($f['status'])) {
            $records->where('records.status', $f['status']);
        }
        if (! empty($f['customer_id'])) {
            $records->where('records.customer_id', $f['customer_id']);
        }
        if (! empty($f['id'])) {
            $records->where('records.id', $f['id']);
        }
        if (! empty($f['retailer_id'])) {
            $id = $f['retailer_id'];
            $records->where(fn ($r) => $r->where('records.retailer_id', $id)->orWhere('records.retailer_b_id', $id)->orWhere(fn ($q) => $q->whereIn('records.source', ['order', 'order_payment', 'order_reservation'])->whereExists(fn ($v) => $v->selectRaw('1')->from('vendor_orders as seller')->whereColumn('seller.order_id', 'records.resource_id')->where('seller.retailer_id', $id))));
        }
        if (! empty($f['from'])) {
            $records->where('records.created_at', '>=', $f['from'].' 00:00:00');
        }
        if (! empty($f['to'])) {
            $records->where('records.created_at', '<', Carbon::parse($f['to'])->addDay()->format('Y-m-d').' 00:00:00');
        }

        return $records->orderByDesc('records.created_at')->orderByDesc('records.id')->orderBy('records.source');
    }

    private function payments()
    {
        $orders = DB::table('marketplace_payments as p')->join('orders as o', 'o.id', '=', 'p.order_id')->selectRaw("p.id,p.status,p.amount_cents,p.currency,NULL as retailer_id,NULL as retailer_b_id,o.user_id as customer_id,o.id as resource_id,'order_payment' as source,p.created_at");
        $exchanges = DB::table('exchange_difference_payments as p')->join('exchange_versions as v', 'v.id', '=', 'p.exchange_version_id')->selectRaw("p.id,p.status,p.amount_cents,p.currency,v.payer_retailer_id as retailer_id,v.payee_retailer_id as retailer_b_id,NULL as customer_id,v.exchange_id as resource_id,'exchange_payment' as source,p.created_at");

        return $orders->unionAll($exchanges);
    }

    private function commissions()
    {
        $orders = DB::table('order_items as i')->join('vendor_orders as v', 'v.id', '=', 'i.vendor_order_id')->join('orders as o', 'o.id', '=', 'v.order_id')->selectRaw("i.id,CASE WHEN i.commission_cents IS NULL THEN 'undefined' ELSE 'defined' END as status,i.commission_cents as amount_cents,v.currency,v.retailer_id,NULL as retailer_b_id,o.user_id as customer_id,v.id as resource_id,'marketplace_commission' as source,i.created_at");
        $legacy = DB::table('sale_items as i')->selectRaw("i.id,'accounted' as status,i.commission_cents as amount_cents,i.currency,i.retailer_id,NULL as retailer_b_id,NULL as customer_id,i.sale_id as resource_id,'legacy_commission' as source,i.created_at");

        return $orders->unionAll($legacy);
    }

    public function link(object $row): ?string
    {
        return match ($row->source) {
            'order','order_payment','order_reservation' => route('admin.marketplace.orders.show', $row->resource_id),'vendor','refund','marketplace_commission' => route('admin.marketplace.vendors.show', $row->resource_id),'exchange','exchange_payment','exchange_reservation' => route('admin.marketplace.exchanges.show', $row->resource_id),default => null
        };
    }
}
