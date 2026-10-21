<?php

declare(strict_types=1);

namespace Belvedere\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

abstract class AlertNotification extends Notification
{
    abstract public function title(): string;

    /** @return list<string> */
    abstract public function lines(): array;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_keys($notifiable->routes);
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('[Belvedere] '.$this->title());

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        return $message;
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $message = (new SlackMessage)->headerBlock($this->title());

        foreach ($this->lines() as $line) {
            $message->sectionBlock(fn ($block) => $block->text($line));
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'lines' => $this->lines(),
        ];
    }
}
