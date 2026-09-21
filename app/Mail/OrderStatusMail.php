<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderStatusMail extends Mailable
{
    public function __construct(
        public string $heading,
        public string $body,
        public string $orderNumber,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading.' — AfrikRaga');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-status');
    }
}
