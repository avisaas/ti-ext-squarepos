@php
    $notes = $formModel->notes ?? [];
@endphp
@if($notes === [])
    <p class="text-muted mb-0">@lang('igniterlabs.squarepos::default.text_no_notes')</p>
@else
    <div class="bg-light border rounded p-3" style="max-height: 24rem; overflow: auto;">
        @foreach($notes as $note)
            <p class="mb-1">{{ $note }}</p>
        @endforeach
    </div>
@endif
