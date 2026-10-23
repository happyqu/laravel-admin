<?php

namespace Encore\Admin\Form;

use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;

class ValidationResponse
{
    public static function make(MessageBag $errors, ?Request $request = null)
    {
        $request ??= request();
        if ($request->expectsJson()) {
            return response()->json([
                'status' => false,
                'message' => $errors->first(),
                'errors' => $errors->getMessages(),
                'validation' => $errors->getMessages(),
            ], 422);
        }

        return back()->withInput($request->except(['password', 'password_confirmation']))->withErrors($errors);
    }
}
