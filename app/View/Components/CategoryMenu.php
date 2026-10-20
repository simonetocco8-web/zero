<?php

namespace App\View\Components;

use App\Services\MarketplaceCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class CategoryMenu extends Component
{
    public Collection $categories;

    public function __construct(MarketplaceCatalog $catalog)
    {
        $this->categories = $catalog->categories();
    }

    public function render(): View
    {
        return view('components.category-menu');
    }
}
