@php
    $exception = $formModel->exception;
    $message = is_array($exception) ? array_get($exception, 'message') : null;
    $trace = is_array($exception) ? array_get($exception, 'trace') : null;
@endphp
@if(!$message && !$trace)
    <p class="text-muted mb-0">@lang('igniterlabs.squarepos::default.text_no_exception')</p>
@else
    @if($message)
        <p class="text-danger mb-2">{{ $message }}</p>
    @endif
    @if($trace)
        <pre class="bg-light border rounded p-3 mb-0 small" style="max-height: 24rem; overflow: auto; white-space: pre-wrap;">{{ $trace }}</pre>
    @endif
@endif
