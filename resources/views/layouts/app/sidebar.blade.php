<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => \App\Models\User::isDarkPalette(auth()->user()?->palette)]) @auth data-palette="{{ auth()->user()->palette }}" @endauth>
    <head>
        @include('partials.head')
    </head>
    <body class="cozy-bg min-h-screen antialiased">
        <flux:sidebar sticky collapsible="mobile" class="bg-zinc-100/80 dark:bg-zinc-900/90">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('files') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="magnifying-glass" :href="route('search')" :current="request()->routeIs('search')" wire:navigate>
                    {{ __('Search') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="folder" :href="route('files')" :current="request()->routeIs('files')" wire:navigate>
                    {{ __('My files') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="star" :href="route('favorites')" :current="request()->routeIs('favorites')" wire:navigate>
                    {{ __('Favorites') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="users" :href="route('shared')" :current="request()->routeIs('shared')" wire:navigate>
                    {{ __('Shared with me') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="clock" :href="route('activity')" :current="request()->routeIs('activity')" wire:navigate>
                    {{ __('Activity') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="chart-pie" :href="route('usage')" :current="request()->routeIs('usage')" wire:navigate>
                    {{ __('Usage') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="trash" :href="route('trash')" :current="request()->routeIs('trash')" wire:navigate>
                    {{ __('Trash') }}
                </flux:sidebar.item>
                @can('admin')
                    <flux:sidebar.item icon="user-group" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>
                        {{ __('Users') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="server-stack" :href="route('admin.storage')" :current="request()->routeIs('admin.storage')" wire:navigate>
                        {{ __('Storage') }}
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>{{ __('Profile') }}</flux:menu.item>
                        <flux:menu.item :href="route('security.edit')" icon="shield-check" wire:navigate>{{ __('Security') }}</flux:menu.item>
                        <flux:menu.item :href="route('appearance.edit')" icon="paint-brush" wire:navigate>{{ __('Appearance') }}</flux:menu.item>
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
        </flux:header>

        {{ $slot }}

        {{-- Persisted, so an upload carries on while you navigate (state lives in the `uploads` store). --}}
        @persist('uploads')
        <div x-data x-show="$store.uploads.items.length" x-cloak
            class="fixed bottom-4 end-4 z-50 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-zinc-200 bg-zinc-50 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                <flux:heading>{{ __('Uploads') }}</flux:heading>
                <flux:button size="xs" variant="ghost" x-on:click="$store.uploads.cancelAll()" x-show="$store.uploads.active" data-test="upload-cancel-all">{{ __('Cancel all') }}</flux:button>
                <flux:button size="xs" variant="ghost" x-on:click="$store.uploads.clear()" x-show="!$store.uploads.active">{{ __('Clear') }}</flux:button>
            </div>
            <div class="space-y-1 border-b border-zinc-200 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-700" x-show="$store.uploads.total.files > 1" data-test="upload-total">
                <div class="flex justify-between">
                    <span x-text="`${$store.uploads.total.done} / ${$store.uploads.total.files} {{ __('files') }}`"></span>
                    <span x-text="`${$store.uploads.total.percent}%`"></span>
                </div>
                <div class="upload-bar h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                    <div class="upload-fill h-full bg-accent transition-all" :style="`width: ${$store.uploads.total.percent}%`"></div>
                </div>
            </div>
            <ul class="max-h-64 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                <template x-for="item in $store.uploads.items" :key="item.key">
                    <li class="space-y-1 px-4 py-2 text-sm" data-test="upload-item">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate" x-text="item.path"></span>
                            <button type="button" class="shrink-0 text-xs text-zinc-500 hover:underline" x-show="['queued', 'uploading'].includes(item.status)" x-on:click="$store.uploads.cancel(item)">{{ __('Cancel') }}</button>
                            <button type="button" class="shrink-0 text-xs text-zinc-500 hover:underline" x-show="item.status === 'error'" x-on:click="$store.uploads.retry(item)">{{ __('Retry') }}</button>
                        </div>
                        <div class="upload-bar h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" x-show="['queued', 'uploading', 'processing', 'done'].includes(item.status)">
                            <div class="upload-fill h-full bg-accent transition-all"
                                :style="`width: ${item.file.size ? Math.round(item.sent / item.file.size * 100) : (item.status === 'done' ? 100 : 0)}%`"></div>
                        </div>
                        <p class="text-xs text-zinc-500" x-show="item.status === 'processing'" data-test="upload-processing">{{ __('Sent. Storing the file…') }}</p>
                        <p class="text-xs text-red-600" x-show="item.status === 'error'" x-text="item.error"></p>
                        <p class="text-xs text-zinc-500" x-show="item.status === 'cancelled'">{{ __('Cancelled') }}</p>
                    </li>
                </template>
            </ul>
        </div>

        {{-- Asked before an upload starts when some of it already exists in the folder (see uploader.js). --}}
        <div x-data x-show="$store.uploads.decision" x-cloak x-trap.noscroll="!!$store.uploads.decision"
            x-on:keydown.escape.window="$store.uploads.decision && $store.uploads.answer('cancel')"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" data-test="upload-conflict">
            <div role="alertdialog" aria-modal="true" aria-labelledby="upload-conflict-title"
                class="w-full max-w-lg space-y-4 border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800" style="border-radius: var(--radius-lg)">
                <template x-if="$store.uploads.decision">
                    <div class="space-y-4">
                        <flux:heading id="upload-conflict-title" size="lg" x-text="$store.uploads.decision.conflicts.length
                            ? `${$store.uploads.decision.conflicts.length} ${$store.uploads.decision.conflicts.length === 1 ? @js(__('item already exists here')) : @js(__('items already exist here'))}`
                            : @js(__('This folder already exists here'))"></flux:heading>

                        <ul class="max-h-48 space-y-1 overflow-y-auto text-sm" data-test="upload-conflict-list">
                            <template x-for="entry in $store.uploads.decision.conflicts.slice(0, 8)" :key="entry.path">
                                <li class="truncate">
                                    <span x-text="entry.path"></span>
                                    <span class="text-zinc-500" x-show="entry.kind === 'folder'">({{ __('a folder: kept under a new name') }})</span>
                                </li>
                            </template>
                            <li class="text-zinc-500" x-show="$store.uploads.decision.conflicts.length > 8"
                                x-text="`${@js(__('and'))} ${$store.uploads.decision.conflicts.length - 8} ${@js(__('more'))}`"></li>
                        </ul>

                        <p class="text-sm text-zinc-600 dark:text-zinc-400" x-show="$store.uploads.decision.merged.length">
                            {{ __('Folders that already exist are merged:') }}
                            <span x-text="$store.uploads.decision.merged.join(', ')"></span>.
                            {{ __('Other files in them are kept.') }}
                        </p>

                        <p class="text-sm text-zinc-600 dark:text-zinc-400" x-show="$store.uploads.decision.conflicts.length">
                            {{ __('Replace swaps the content and keeps the file where it is, with its links and favorites. Keep both saves the new file as "name (2)". Skip uploads only what is new.') }}
                        </p>

                        <div class="flex flex-wrap justify-end gap-2">
                            <flux:button x-on:click="$store.uploads.answer('cancel')" data-test="conflict-cancel">{{ __('Cancel') }}</flux:button>
                            <flux:button x-on:click="$store.uploads.answer('skip')" x-show="$store.uploads.decision.conflicts.length" data-test="conflict-skip">{{ __('Skip existing') }}</flux:button>
                            <flux:button x-on:click="$store.uploads.answer('keep')" data-test="conflict-keep">
                                <span x-show="$store.uploads.decision.conflicts.length">{{ __('Keep both') }}</span>
                                <span x-show="!$store.uploads.decision.conflicts.length" x-cloak>{{ __('Merge') }}</span>
                            </flux:button>
                            <flux:button variant="primary" x-on:click="$store.uploads.answer('replace')" x-show="$store.uploads.decision.conflicts.length" data-test="conflict-replace">{{ __('Replace') }}</flux:button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        @endpersist

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
