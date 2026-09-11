<x-layouts::auth :title="__('Set up Lafiel')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Welcome to Lafiel')"
            :description="__('Create the one local administrator. Public sign-up stays off — this screen appears only until the administrator exists.')"
        />

        <form method="POST" action="{{ route('setup.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="name"
                :label="__('Name')"
                type="text"
                required
                autofocus
                autocomplete="name"
            />

            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="setup-button">
                    {{ __('Create administrator') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
