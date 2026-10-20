<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalNotice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $heading, public string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[AcadBest] '.$this->heading);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<h3>'.e($this->heading).'</h3><p>'.nl2br(e($this->body)).'</p>');
    }
}