<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{-- Imperial Ledger shell (docs/design-system.md): light paper canvas,
         navy command rail on desktop, navy top bar and bottom nav on mobile. --}}
    <body class="min-h-screen bg-canvas font-sans text-ink antialiased">
        {{-- --- Command rail (desktop) --- --}}
        <aside class="fixed inset-y-0 start-0 z-40 hidden w-60 flex-col bg-command text-on-command lg:flex">
            <div class="flex items-center gap-3 px-6 pt-6 pb-8">
                {{-- The lafiel brand roundel (docs/design-system.md) — same
                     cached asset as the favicon. --}}
                <x-app-logo-icon class="size-9 shrink-0" />
                <span class="font-serif text-2xl leading-none tracking-wide">Lafiel</span>
            </div>

            <nav class="flex-1 space-y-1 px-3" aria-label="{{ __('Main') }}">
                @foreach ([
                    'overview' => ['label' => __('Overview'), 'icon' => 'home'],
                    'costs.index' => ['label' => __('Services'), 'icon' => 'server-stack'],
                    'costs.renewals' => ['label' => __('Renewals'), 'icon' => 'arrow-path'],
                    'costs.history' => ['label' => __('History'), 'icon' => 'clock'],
                    'providers.index' => ['label' => __('Providers'), 'icon' => 'users'],
                    'syncs.index' => ['label' => __('Syncs'), 'icon' => 'queue-list'],
                ] as $route => $item)
                    <a
                        href="{{ route($route) }}"
                        wire:navigate
                        @if (request()->routeIs($route))
                            aria-current="page"
                        @endif
                        class="flex items-center gap-3 rounded-control px-3 py-2.5 text-sm font-medium transition-colors
                            {{ request()->routeIs($route)
                                ? 'bg-line-command text-on-command'
                                : 'text-on-command/75 hover:bg-line-command/60 hover:text-on-command' }}"
                        data-test="nav-{{ $route }}"
                    >
                        <flux:icon :name="$item['icon']" class="size-5" />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="px-6 pb-6">
                <div class="border-t border-line-command pt-4">
                    <livewire:shell.sync-state />
                </div>

                <p class="mt-4 text-xs text-on-command/50">v{{ config('app.version') }}</p>

                <div class="mt-4">
                    <flux:dropdown position="top" align="start">
                        <flux:profile
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                            class="text-on-command"
                            data-test="rail-menu-button"
                        />

                        <flux:menu>
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>

                            <flux:menu.separator />

                            <flux:menu.radio.group>
                                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate data-test="user-menu-settings">
                                    {{ __('Settings') }}
                                </flux:menu.item>
                            </flux:menu.radio.group>

                            <flux:menu.separator />

                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item
                                    as="button"
                                    type="submit"
                                    icon="arrow-right-start-on-rectangle"
                                    class="w-full cursor-pointer"
                                    data-test="logout-button"
                                >
                                    {{ __('Log out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        </aside>

        <div class="flex min-h-screen flex-col lg:ps-60">
            {{-- --- Top bar: navy on mobile (mockup 05), paper on desktop --- --}}
            <header class="sticky top-0 z-30 border-b border-line bg-surface/90 backdrop-blur max-lg:border-line-command max-lg:bg-command max-lg:text-on-command">
                <div class="flex h-14 items-center gap-3 px-4 sm:px-6">
                    {{-- Brand: in flow on mobile (the rail wordmark covers desktop) --}}
                    <div class="flex items-center gap-2 lg:hidden">
                        <x-app-logo-icon class="size-5" />
                        <span class="font-serif text-lg leading-none tracking-wide">Lafiel</span>
                    </div>

                    <flux:heading class="hidden whitespace-nowrap lg:block" level="2">{{ __('Fleet ledger') }}</flux:heading>

                    <flux:spacer />

                    <livewire:shell.sync-state class="max-lg:hidden" />

                    <livewire:shell.sync-button class="max-lg:bg-transparent max-lg:px-2 max-lg:text-on-command max-lg:hover:bg-white/10 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover" />

                    {{-- Mobile only: the command rail's user menu covers desktop. --}}
                    <flux:dropdown position="bottom" align="end" class="lg:hidden">
                        <flux:profile
                            :initials="auth()->user()->initials()"
                            :chevron="false"
                            class="text-on-command"
                            :aria-label="__('Account menu')"
                            data-test="mobile-account-menu"
                        />

                        <flux:menu>
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>

                            <flux:menu.separator />

                            <flux:menu.radio.group>
                                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                    {{ __('Settings') }}
                                </flux:menu.item>
                            </flux:menu.radio.group>

                            <flux:menu.separator />

                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item
                                    as="button"
                                    type="submit"
                                    icon="arrow-right-start-on-rectangle"
                                    class="w-full cursor-pointer"
                                >
                                    {{ __('Log out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </header>

            {{-- Plain main on purpose: nesting <flux:main> here would pull
                Flux's body grid template into this wrapper and collapse the
                header into a min-content grid column. --}}
            <main class="mx-auto w-full max-w-[1440px] flex-1 px-4 pb-24 pt-6 sm:px-6 lg:pb-10">
                {{ $slot }}
            </main>
        </div>

        {{-- --- Bottom nav (mobile) --- --}}
        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="{{ __('Main') }}">
            <div class="grid grid-cols-5">
                @foreach ([
                    'overview' => ['label' => __('Overview'), 'icon' => 'home'],
                    'costs.index' => ['label' => __('Services'), 'icon' => 'server-stack'],
                    'costs.renewals' => ['label' => __('Renewals'), 'icon' => 'arrow-path'],
                    'providers.index' => ['label' => __('Providers'), 'icon' => 'users'],
                    'syncs.index' => ['label' => __('Syncs'), 'icon' => 'queue-list'],
                ] as $route => $item)
                    <a
                        href="{{ route($route) }}"
                        wire:navigate
                        @if (request()->routeIs($route))
                            aria-current="page"
                        @endif
                        class="flex min-h-[44px] flex-col items-center justify-center gap-1 py-2 text-xs font-medium
                            {{ request()->routeIs($route) ? 'text-info' : 'text-ink-muted' }}"
                        data-test="nav-{{ $route }}"
                    >
                        <flux:icon :name="$item['icon']" class="size-5" />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        </nav>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
