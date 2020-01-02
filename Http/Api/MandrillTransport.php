<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\Mailchimp\Http\Api;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SmtpEnvelope;
use Symfony\Component\Mailer\Transport\Http\Api\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\NamedAddress;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Kevin Verschaeve
 * @experimental in 4.3
 */
class MandrillTransport extends AbstractApiTransport
{
    private const ENDPOINT = 'https://mandrillapp.com/api/1.0/messages/send.json';

    private $key;

    public function __construct(string $key, HttpClientInterface $client = null, EventDispatcherInterface $dispatcher = null, LoggerInterface $logger = null)
    {
        $this->key = $key;

        parent::__construct($client, $dispatcher, $logger);
    }

    protected function doSendEmail(Email $email, SmtpEnvelope $envelope): void
    {
        $response = $this->client->request('POST', self::ENDPOINT, [
            'json' => $this->getPayload($email, $envelope),
        ]);

        if (200 !== $response->getStatusCode()) {
            $result = $response->toArray(false);
            if ('error' === ($result['status'] ?? false)) {
                throw new TransportException(sprintf('Unable to send an email: %s (code %s).', $result['message'], $result['code']));
            }

            throw new TransportException(sprintf('Unable to send an email (code %s).', $result['code']));
        }
    }

    private function getPayload(Email $email, SmtpEnvelope $envelope): array
    {
        $payload = [
            'key' => $this->key,
            'message' => [
                'html' => $email->getHtmlBody(),
                'text' => $email->getTextBody(),
                'subject' => $email->getSubject(),
                'from_email' => $envelope->getSender()->getAddress(),
                'to' => $this->getRecipients($email, $envelope),
            ],
        ];

        if ($envelope->getSender() instanceof NamedAddress) {
            $payload['message']['from_name'] = $envelope->getSender()->getName();
        }

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $att = [
                'content' => $attachment->bodyToString(),
                'type' => $headers->get('Content-Type')->getBody(),
            ];

            if ($name = $headers->getHeaderParameter('Content-Disposition', 'name')) {
                $att['name'] = $name;
            }

            if ('inline' === $disposition) {
                $payload['message']['images'][] = $att;
            } else {
                $payload['message']['attachments'][] = $att;
            }
        }

        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type'];
        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }

            $payload['message']['headers'][] = $name.': '.$header->toString();
        }

        return $payload;
    }

    protected function getRecipients(Email $email, SmtpEnvelope $envelope): array
    {
        $recipients = [];
        foreach ($envelope->getRecipients() as $recipient) {
            $type = 'to';
            if (\in_array($recipient, $email->getBcc(), true)) {
                $type = 'bcc';
            } elseif (\in_array($recipient, $email->getCc(), true)) {
                $type = 'cc';
            }

            $recipientPayload = [
                'email' => $recipient->getAddress(),
                'type' => $type,
            ];

            if ($recipient instanceof NamedAddress) {
                $recipientPayload['name'] = $recipient->getName();
            }

            $recipients[] = $recipientPayload;
        }

        return $recipients;
    }
}
