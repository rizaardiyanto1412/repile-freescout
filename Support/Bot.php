<?php

namespace Modules\Repile\Support;

use App\User;

class Bot
{
    public static function user()
    {
        $id = (int) \Option::get('repile.bot_user_id');
        if ($id) {
            $user = User::find($id);
            if ($user && !$user->isDeleted()) {
                return self::ensurePhoto($user);
            }
        }

        return self::create();
    }

    public static function id()
    {
        return (int) \Option::get('repile.bot_user_id');
    }

    public static function isBot($user_id)
    {
        $id = self::id();

        return $id !== 0 && (int) $user_id === $id;
    }

    private static function create()
    {
        $host = parse_url(url('/'), PHP_URL_HOST) ?: 'freescout';
        $email = 'repile-bot@'.preg_replace('/[^a-z0-9.-]/i', '', $host).'.invalid';

        $user = User::where('email', $email)->first();
        if (!$user) {
            $user = new User();
            $user->first_name = 'Repile';
            $user->last_name = '';
            $user->email = $email;
            $user->password = \Hash::make(bin2hex(random_bytes(24)));
            $user->role = User::ROLE_USER;
            $user->type = User::TYPE_ROBOT;
            $user->status = User::STATUS_ACTIVE;
            $user->save();
        } elseif ($user->isDeleted()) {
            $user->status = User::STATUS_ACTIVE;
            $user->save();
        }

        \Option::set('repile.bot_user_id', $user->id);

        return self::ensurePhoto($user);
    }

    public static function photoUrl()
    {
        $user = self::id() ? User::find(self::id()) : null;
        if ($user && !$user->isDeleted()) {
            $user = self::ensurePhoto($user);
        }
        if ($user && $user->photo_url) {
            return $user->getPhotoUrl();
        }

        return \Module::getPublicPath('repile').'/img/repile-avatar.png';
    }

    private static function ensurePhoto(User $user)
    {
        if ($user->photo_url) {
            return $user;
        }
        try {
            $file = $user->savePhoto(__DIR__.'/../Public/img/repile-avatar.jpg', 'image/jpeg');
            if ($file) {
                $user->photo_url = $file;
                $user->save();
            }
        } catch (\Exception $e) {
            \Helper::logException($e, '[Repile] avatar');
        }

        return $user;
    }
}
