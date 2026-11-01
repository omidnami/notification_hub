<?php

namespace App\Services\Email;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class EmailMimeFactory
{
    public function create(array $message): Email
    {
        $mail = (new Email())
            ->from($message['from'])
            ->to(...$message['to'])
            ->subject($message['subject']);

        if (!empty($message['cc'])) {
            $mail->cc(...$message['cc']);
        }
        if (!empty($message['bcc'])) {
            $mail->bcc(...$message['bcc']);
        }
        if (!empty($message['reply_to'])) {
            $mail->replyTo($message['reply_to']);
        }

        if (isset($message['text']) && $message['text'] !== '') {
            $mail->text($message['text']);
        }
        if (isset($message['html']) && $message['html'] !== '') {
            $mail->html($message['html']);
        }

        foreach ($message['attachments'] ?? [] as $attachment) {
            $data = base64_decode($attachment['content_base64'], true);
            if ($data === false) {
                throw new \InvalidArgumentException('Invalid email attachment.');
            }
            $mail->addPart(new DataPart(
                $data,
                $attachment['filename'],
                $attachment['content_type'] ?? 'application/octet-stream',
            ));
        }

        return $mail;
    }
}
