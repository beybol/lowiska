@props([
    'description', 
    'createUrl',
    'createLabel',
    'listUrl' => null,
    'listLabel' => null,
    'count' => 0
])

<div class="space-y-4">
    <p class="text-gray-600">
        {{ $description }}
    </p>

    <div class="flex gap-4">
        <a 
            href="{{ $createUrl }}" 
            class="inline-flex gap-1 items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700"
        >
            <x-icons.plus />
            {{ $createLabel }}
        </a>
        
        @if($count > 0 && $listUrl && $listLabel)
            <a 
                href="{{ $listUrl }}" 
                class="inline-flex gap-2 items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
            >
                <x-icons.view />
                {{ $listLabel }}
            </a>
        @endif
    </div>
</div>
