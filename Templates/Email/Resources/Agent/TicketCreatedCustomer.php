<?php

namespace Webkul\UVDesk\CoreBundle\Templates\Email\Resources\Agent;

use Webkul\UVDesk\CoreBundle\Templates\Email\UVDeskEmailTemplateInterface;

abstract class TicketCreatedCustomer implements UVDeskEmailTemplateInterface
{
    private static $type = "ticket";
    private static $name = 'Customer ticket generated mail to agent';
    private static $subject = 'New ticket #{% ticket.id %} generated';
    private static $message = <<<MESSAGE
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p style="text-align: center;">{%global.companyLogo%}</p>
    <p style="text-align: center;">
        <br />
    </p>
    <p style="text-align: center;">
        <b>
            <span style="font-size: 18px;">Ticket generated!!</span>
        </b>
    </p>
    <p style="text-align: center; ">
        <br />
    </p>
    <br />
    <p></p>
    <p>Hello&nbsp;{%ticket.customerName%},</p>
    <p>
        <br />
    </p>
    <p></p>
    <p></p>
    <p>A new ticket&nbsp;{%ticket.id%} has been generated by&nbsp;{%ticket.customerName%} from {%ticket.customerEmail%}. Hit on
        the link provided so that you can have the access to the ticket&nbsp;{%ticket.agentLink%}.</p>
    <p>
        <br />
    </p>
    <p>Here goes the ticket message:</p>
    <p>{%ticket.message%}
        <br />
    </p>
    <p>
        <span style="line-height: 1.42857143;">
            <br />
        </span>
    </p>
    <p>Thanks and Regards</p>
    <p>{%global.companyName%}
        <br />
    </p>
    <br />
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
    <p></p>
MESSAGE;

    public static function getName()
    {
        return self::$name;
    }

    public static function getTemplateType()
    {
        return self::$type;
    }

    public static function getSubject()
    {
        return self::$subject;
    }

    public static function getMessage()
    {
        return self::$message;
    }
}