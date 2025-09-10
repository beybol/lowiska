@props([
    'tabKey',
    'label',
    'count' => 0
])

<button 
    @click="activeTab = '{{ $tabKey }}'" 
    :class="activeTab === '{{ $tabKey }}' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap"
>
    {{ $label }}

    @if($count > 0)
        <span 
            class="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs"
        >
            {{ $count }}
        </span>
    @endif
</button>
