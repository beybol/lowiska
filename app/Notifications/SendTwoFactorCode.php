<?php

namespace App\Notifications;

use App\Mail\TwoFactorCodeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SendTwoFactorCode extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return TwoFactorCodeMail
     */
    public function toMail($notifiable)
    {
        // Wspólny layout maili (zadanie 040) zamiast domyślnego szablonu Laravela.
        return (new TwoFactorCodeMail((string) $notifiable->two_factor_code, route('verify.index')))
            ->to($notifiable->email);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
