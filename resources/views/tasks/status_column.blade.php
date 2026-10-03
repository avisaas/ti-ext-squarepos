@php
    $task = $record ?? $formModel ?? null;
@endphp
@if($task)
    {!! $task->status_name !!}
@endif
