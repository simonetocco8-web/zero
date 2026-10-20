<div class="category-menu relative" x-data="categoryMenu" x-id="['category-list']" @click.outside="close()" @keydown.escape.stop.prevent="close(true)" @focusout="if (!$el.contains($event.relatedTarget)) close()">
    <button type="button" class="btn btn-secondary" x-ref="trigger" :aria-controls="$id('category-list')" :aria-expanded="String(open)" @click="open = !open" @keydown.arrow-down.prevent="openAt(0)" @keydown.arrow-up.prevent="openAt(-1)">
        Categorie <x-icon name="chevron-down" class="size-4 transition-transform" ::class="{ 'rotate-180': open }" />
    </button>
    <nav x-cloak x-show="open" :id="$id('category-list')" x-ref="panel" aria-label="Categorie marketplace" class="category-dropdown" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.home.prevent="focusAt(0)" @keydown.end.prevent="focusAt(-1)">
        @forelse($categories as $category)
            <a class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold hover:bg-raised" href="{{ route('marketplace.category', $category->slug) }}"><x-category-icon :category="$category" class="size-6 text-brand-700" />{{ $category->name }}</a>
        @empty
            <p class="px-3 py-3 text-sm text-muted">Nessuna categoria disponibile.</p>
        @endforelse
    </nav>
</div>
