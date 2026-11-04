<?php
declare(strict_types=1);

namespace AtoBbee\Http;

use AtoBbee\Auth;

final class AuthController
{
    public function loginForm(): void
    {
        if ($u = Auth::user()) redirect(Auth::home($u));
        view('auth/login');
    }

    public function login(): void
    {
        if (!Auth::attempt((string)input('email'), (string)($_POST['password'] ?? ''))) {
            $_SESSION['old'] = ['email' => input('email')];
            flash('error', 'That email and password combination does not match an account.');
            redirect('/login');
        }
        $next = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        redirect(is_string($next) && str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : Auth::home(Auth::user()));
    }

    public function registerForm(): void
    {
        view('auth/register', ['role' => in_array(input('role'), ['sender', 'driver', 'business'], true) ? input('role') : 'sender']);
    }

    public function register(): void
    {
        $data = [
            'first_name' => (string)input('first_name'),
            'last_name'  => (string)input('last_name'),
            'email'      => strtolower((string)input('email')),
            'role'       => (string)input('role'),
        ];
        $password = (string)($_POST['password'] ?? '');
        $errors = [];
        if ($data['first_name'] === '' || $data['last_name'] === '') $errors[] = 'Please fill in your name.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please use a valid email address.';
        if (!in_array($data['role'], ['sender', 'driver', 'business'], true)) $errors[] = 'Choose what you want to do on AtoBbee.';
        if (strlen($password) < 10) $errors[] = 'Use a password of at least 10 characters.';
        if (!input('terms')) $errors[] = 'Please accept the Terms of Service and Privacy Policy.';
        if (db()->value('SELECT id FROM users WHERE email = ?', [$data['email']])) $errors[] = 'An account with that email already exists.';

        if ($errors) {
            $_SESSION['old'] = $data;
            foreach ($errors as $e) flash('error', $e);
            redirect('/register?role=' . urlencode($data['role']));
        }
        $id = db()->insert('users', $data + ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        Auth::login($id);
        flash('success', match ($data['role']) {
            'driver' => 'Welcome! Add your vehicle and submit your verification to start earning.',
            'business' => 'Welcome! Set up your pickup point below.',
            default => 'Welcome to AtoBbee. Ready to send your first package?',
        });
        redirect(match ($data['role']) { 'driver' => '/driver/profile', 'business' => '/point', default => '/send' });
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/');
    }
}
