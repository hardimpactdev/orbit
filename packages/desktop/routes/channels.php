<?php

use Illuminate\Support\Facades\Broadcast;

// Only register broadcast channels if broadcasting is properly configured
// This prevents errors on first launch before environment is set up
if (config('broadcasting.default') !== 'null' && config('broadcasting.connections.reverb.secret')) {
    Broadcast::channel('App.Models.User.{id}', fn ($user, $id): bool => (int) $user->id === (int) $id);
}
