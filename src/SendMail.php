<?php

namespace Spliff\Utils;

use Exception;

class SendMail
{
    public const CRLF = "\r\n";

    public int $maxAttachmentSize;

    private string $encoding;
    public string $charset;

    protected string $from = '';
    protected string $fromName = '';
    protected string $replyTo = '';

    private array $recipients = [];

    public array $headers;
    public string $subject;
    public string $body;
    public string $altBody;

    public string $smtpDsn = '';
    private string $cmdlineSendmailParams = '';
    protected bool $isHtml = false;

    private array $filesToAttach;

    public static function newInstance(): ?SendMail
    {
        $sm = new SendMail();
        $sm->maxAttachmentSize = 10485760; //10 MB
        $sm->charset = 'UTF-8';
        return $sm;
    }

    public function setSmtpDsn(string $dsn): SendMail
    {
        $this->smtpDsn = $dsn;
        return $this;
    }


    public function setFrom(string $email, string $name = ''): SendMail
    {
        if (empty($email)) {
            throw new SendMailException("From email cannot be empty");
        }
        $this->from = $email;

        if (!empty($name)) {
            $this->fromName = $name;
        }
        return $this;
    }

    public function setSubject(string $subj): SendMail
    {
        $this->subject = $subj;
        return $this;
    }

    public function setReplyTo(string $email): SendMail
    {
        $this->replyTo = $email;
        return $this;
    }

    public function addRecipient(string $email): SendMail
    {
        array_push($this->recipients, $email);
        return $this;
    }

    public function clearRecipients(): SendMail
    {
        $this->recipients = [];
        return $this;
    }

    public function addHeader(string $key, string $value): SendMail
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function setBody(string $body): SendMail
    {
        $this->body = $body;
        return $this;
    }

    public function setCmdlineSendmailParams(string $params): SendMail
    {
        $this->cmdlineSendmailParams = $params;
        return $this;
    }

    public function setHtml(): SendMail
    {
        $this->isHtml = true;
        return $this;
    }

    public function setPlain(): SendMail
    {
        $this->isHtml = false;
        return $this;
    }

    public function addAttachment(string $fname): SendMail
    {
        $this->filesToAttach[] = $fname;
        return $this;
    }

    public function send(): SendMail
    {
        $this->prepareAttachments();
        $this->prepareHeaders();

        print_r($this);

        if (!empty($this->smtpDsn)) {
            $this->sendOverSocket();
        } else {
            //fallback sendmail
            mail(array_shift($this->recipients), $this->subject, $this->body, [], $this->cmdlineSendmailParams);
        }

        return $this;
    }

    private function defaults(array $d)
    {
        foreach ($d as $key => $value) {
            if (!isset($this->headers[$key])) {
                $this->headers[$key] = $value;
            }
        }
    }

    private function prepareHeaders()
    {

        $this->defaults([
            'Content-Type' => ''.$this->bodyContentType().'; charset="'.$this->charset.'"',
            'From' => $this->from,
            'Subject' => $this->subject,
            'Reply-To' => ($this->replyTo ? $this->replyTo : $this->from),
        ]);

        //glue result in front of body
        foreach ($this->headers as $k => $v) {
            $this->body = $k.': '.$v.self::CRLF.$this->body;
        }
    }

    private function prepareAttachments()
    {
        if (empty($this->filesToAttach)) {
            return;
        }
        $mimeBoundary = md5(date('r', time()));
        $this->headers['Content-Type'] = 'multipart/mixed; boundary="'.$mimeBoundary.'"';
        $this->headers['MIME-Version'] = '1.0'; //?


        $newBody = implode(self::CRLF, [
            '',
            'This is a multi-part message in MIME format.',
            '',
            '--'.$mimeBoundary,
            'Content-Type: '.$this->bodyContentType().'; charset="'.$this->charset.'"',
            'Content-Transfer-Encoding: 8bit',
        ]).self::CRLF.self::CRLF.$this->body.self::CRLF.self::CRLF; //note double crlf at end

        foreach ($this->filesToAttach as $file) {

            $fileSize = filesize($file);
            $mimeType = mime_content_type($file);
            if (empty($mimeType)) {
                $mimeType = 'application/octet-stream';
            }
            if ($fileSize > $this->maxAttachmentSize) {
                throw new SendMailException("file ".$file." is too big to attach");
            }
            $handle = fopen($file, "r");
            $content = fread($handle, $fileSize);
            fclose($handle);
            $content = chunk_split(base64_encode($content));

            $newBody .= self::CRLF."--".$mimeBoundary.self::CRLF;
            $newBody .= 'Content-Type: '.$mimeType.'; name="'.basename($file).'"'.self::CRLF; // use different content types here
            $newBody .= 'Content-Transfer-Encoding: base64'.self::CRLF;
            $newBody .= 'Content-Disposition: attachment; filename="'.basename($file).'"'.self::CRLF;
            $newBody .= self::CRLF.$content.self::CRLF.self::CRLF; //pre double crlf
            $newBody .= self::CRLF."--".$mimeBoundary.self::CRLF;
        }

        $this->altBody = $this->body;
        $this->body = $newBody;

    }

    private function bodyContentType(): string
    {
        return "text/".($this->isHtml ? "html" : "plain");
    }


    private function sendOverSocket()
    {
        $errno = 0;
        $errstr = "";
        $c = parse_url($this->smtpDsn);

        print_r($c['scheme'].'://'.$c['host']);
        $socket = fsockopen($c['scheme'].'://'.$c['host'], $c['port'], $errno, $errstr, 30);

        print_r($c);

        try {
            if (!$socket) {
                throw new SendMailException('could not connect to smtp host '
                    .(empty($c['scheme']) ? '' : $c['scheme'].'://').$c['host'].':'.$c['port']
                    .' ('.$errno.') '.$errstr);
            }
            $this->testSocketResponse($socket, 'Connect', '220');
            fputs($socket, 'EHLO '.$c['host'].self::CRLF);

            try {
                $this->testSocketResponse($socket, 'EHLO', '250', true);
            } catch (Exception $e) {
                fputs($socket, 'HELO '.$c['host'].self::CRLF);
                $this->testSocketResponse($socket, 'HELO', '250');
            }

            if (!empty($c['user'])) {

                fputs($socket, 'AUTH LOGIN'.self::CRLF);
                $this->testSocketResponse($socket, 'AUTH', '334');

                fputs($socket, base64_encode($c['user']).self::CRLF);
                $this->testSocketResponse($socket, 'User', '334');

                fputs($socket, base64_encode($c['pass']).self::CRLF);
                $this->testSocketResponse($socket, 'Password', '235');
            }

            fputs($socket, 'MAIL FROM: <'. $this->from .'>'.self::CRLF);
            $this->testSocketResponse($socket, 'MAIL', '250');

            foreach ($this->recipients as $to) {
                fputs($socket, 'RCPT TO: <'.$to.'>'.self::CRLF);
                $this->testSocketResponse($socket, 'RCPT', '250');
            }

            fputs($socket, 'DATA'.self::CRLF);
            $this->testSocketResponse($socket, 'DATA', '354');

            fputs($socket, $this->body.self::CRLF.'.'.self::CRLF); //note dot

            $this->testSocketResponse($socket, 'End data', '250');

        } catch (Exception $e) {
            throw $e;
        } finally {
            fputs($socket, 'QUIT'.self::CRLF);
            fclose($socket);
        }

    }

    private function testSocketResponse($socket, $title, $expected, $skip = false)
    {
        $i = 100;
        $response = '';
        while (substr($response, 3, 1) !== ' ' && ($i > 0)) {
            $response = fgets($socket, 256);
            $i--;
            echo "<<<\n".$response."\n\n";
            if (empty($response)) {
                throw new SendMailException("could not get smtp server response from socket for ".$title);
                // trigger_error("could not get smtp server response from socket for ".$title);
            }
        }

        $code = substr($response, 0, 3);
        if ($code !== $expected) {
            if (!$skip) {
                // trigger_error('unexpected response from socket, expected '
                throw new SendMailException('unexpected response from socket, expected '
                        .$expected.', got '.$code.' for '.$title);
            }
        }
    }

}

class SendMailException extends Exception
{
}
