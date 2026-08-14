@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-center">
    {{-- text-white explícito: o heading do Flux rende apagado sobre o cartão
         escuro do auth, como nos títulos das telas do produto. --}}
    <flux:heading size="xl" class="text-white">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
