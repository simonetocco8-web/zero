@if($paginator->hasPages())
<nav aria-label="Paginazione" class="mt-5 flex flex-wrap items-center gap-2">
@if($paginator->onFirstPage())<span class="btn btn-secondary" aria-disabled="true">Precedente</span>@else<a class="btn btn-secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev">Precedente</a>@endif
@foreach($elements as $element)
@if(is_string($element))<span aria-hidden="true" class="p-2">{{ $element }}</span>@endif
@if(is_array($element))@foreach($element as $page=>$url)
@if($page===$paginator->currentPage())<span class="btn btn-primary" aria-current="page"><span class="sr-only">Pagina </span>{{ $page }}</span>@else<a class="btn btn-secondary" href="{{ $url }}" aria-label="Pagina {{ $page }}">{{ $page }}</a>@endif
@endforeach @endif
@endforeach
@if($paginator->hasMorePages())<a class="btn btn-secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">Successiva</a>@else<span class="btn btn-secondary" aria-disabled="true">Successiva</span>@endif
<p class="w-full text-sm text-slate-600">Pagina {{ $paginator->currentPage() }} di {{ $paginator->lastPage() }}</p>
</nav>
@endif
