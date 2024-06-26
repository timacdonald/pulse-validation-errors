<x-pulse::card id="validation-card" :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Validation Errors"
        title="Time: {{ number_format($time) }}ms; Run at: {{ $runAt }};"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($errors->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Error</x-pulse::th>
                        <x-pulse::th class="text-right">Count</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($errors->take(100) as $error)
                        <tr wire:key="{{ $error->key_hash }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $error->key_hash }}">
                            <x-pulse::td class="overflow-hidden max-w-[1px] space-y-2">
                                <div class="truncate" title="{{ $error->bag ? $error->bag.' @ ' : '' }}{{ $error->name }}{{ $error->message ? ': '.$error->message : '' }}">
                                    <span class="font-medium">{{ $error->bag ? $error->bag.' @ ' : '' }}{{ $error->name }}{{ $error->message ? ':' : '' }}</span>
                                    @if ($error->message)
                                        <span class="text-gray-500 dark:text-gray-400">{{ $error->message }}</span>
                                    @endif
                                </div>
                                <div class="flex gap-2">
                                    <x-pulse::http-method-badge :method="$error->method" />
                                    <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $error->uri }}">
                                        {{ $error->uri }}
                                    </code>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $error->action }}">
                                    {{ $error->action }}
                                </p>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold w-32">
                                @if ($config['sample_rate'] !== 1)
                                    <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($error->count) }}">~{{ number_format($error->count * (1 / $config['sample_rate'])) }}</span>
                                @else
                                    {{ number_format($error->count) }}
                                @endif
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>

            @if ($errors->count() > 100)
                <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
            @endif
        @endif
    </x-pulse::scroll>
</x-pulse::card>
