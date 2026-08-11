<x-mail::message>
# {{ __(':name joined :team', ['name' => $member->name, 'team' => $team->name]) }}

{{ __(':name accepted the invitation and is now a member of :team.', [
    'name' => $member->name,
    'team' => $team->name,
]) }}

<x-mail::button :url="$url">
{{ __('View team') }}
</x-mail::button>

{{ __('You are receiving this because you are a member of this team. You can change what you hear about in your notification settings.') }}
</x-mail::message>
