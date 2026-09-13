@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Lafiel')" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Lafiel')" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:brand>
@endif
