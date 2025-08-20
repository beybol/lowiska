<?php

namespace Tests\Feature\Auth;

test('Login screen can be rendered', function () {
    $this->get('/login')
        ->assertStatus(200)
        ->assertSee(__('Login'))
        ->assertSee(__('Register fishery'));
});
