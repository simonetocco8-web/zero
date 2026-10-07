<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AdminFilters
{
    public function apply(Builder $query, array $filters, bool $retailers = false, bool $inventory = false): Builder
    {
        $date = $inventory ? DB::raw('COALESCE(submitted_at, created_at)') : 'created_at';
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['company'])) {
            $company = $filters['company'];
            if ($retailers) {
                $query->where('company_name', 'like', '%'.$company.'%');
            } else {
                $query->whereHas('retailer', fn ($q) => $q->where('company_name', 'like', '%'.$company.'%'));
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate($date, '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate($date, '<=', $filters['to']);
        }

        return $query;
    }
}
