@props(['step' => 1])
<ol class="my-6 grid grid-cols-2 gap-3 text-sm" aria-label="Passaggi registrazione">
<li class="min-w-0 border-b-2 pb-3 {{ $step === 1 ? 'border-brand-700 font-semibold' : 'border-line' }}" @if($step === 1) aria-current="step" @endif><span class="mr-1">{{ $step === 2 ? '✓' : '1' }}</span> Partita IVA</li>
<li class="min-w-0 border-b-2 pb-3 {{ $step === 2 ? 'border-brand-700 font-semibold' : 'border-line text-muted' }}" @if($step === 2) aria-current="step" @endif><span class="mr-1">2</span> Dati azienda e account</li>
</ol>
