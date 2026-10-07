<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AdminFilters
{
    public function apply(Builder $query, array $filters, bool $retailers = false, bool $inventory = false): Builder
    {
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
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            if (empty($filters[$key])) {
                continue;
            }
            $boundary = Carbon::createFromFormat('!Y-m-d', $filters[$key]);
            if ($key === 'to') {
                $boundary->addDay();
            }
            if ($inventory) {
                $query->where(fn ($q) => $q->where('submitted_at', $operator, $boundary)
                    ->orWhere(fn ($fallback) => $fallback->whereNull('submitted_at')->where('created_at', $operator, $boundary)));
            } else {
                $query->where('created_at', $operator, $boundary);
            }
        }

        return $query;
    }
}
