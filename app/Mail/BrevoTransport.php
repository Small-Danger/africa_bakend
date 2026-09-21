<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

final class BrevoTransport extends AbstractTransport
{
    public function __construct(private string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? new Address(
            (string) config('mail.from.address'),
            (string) config('mail.from.name'),
        );

        $html = $email->getHtmlBody();
        if (! is_string($html) || $html === '') {
            $html = nl2br(e((string) $email->getTextBody()));
        }

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->timeout(8)->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'name' => $from->getName() !== '' ? $from->getName() : 'AfrikRaga',
                'email' => $from->getAddress(),
            ],
            'to' => collect($email->getTo() ?: [])->map(fn (Address $address) => array_filter([
                'email' => $address->getAddress(),
                'name' => $address->getName() !== '' ? $address->getName() : null,
            ]))->values()->all(),
            'subject' => (string) $email->getSubject(),
            'htmlContent' => $html,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Brevo API '.$response->status().': '.$response->body());
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
