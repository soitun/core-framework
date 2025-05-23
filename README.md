<p align="center"><a href="https://www.uvdesk.com/en/" target="_blank">
    <img src="https://s3-ap-southeast-1.amazonaws.com/cdn.uvdesk.com/uvdesk/bundles/webkuldefault/images/uvdesk-wide.svg">
</a></p>

<p align="center">
    <a href="https://packagist.org/packages/uvdesk/core-framework"><img src="https://poser.pugx.org/uvdesk/core-framework/v/stable.svg" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/uvdesk/core-framework"><img src="https://poser.pugx.org/uvdesk/core-framework/d/total.svg" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/uvdesk/core-framework"><img src="https://poser.pugx.org/uvdesk/core-framework/license.svg" alt="License"></a>
    <a href="https://gitter.im/uvdesk/core-framework"><img src="https://badges.gitter.im/uvdesk/core-framework.svg" alt="connect on gitter"></a>
</p>

The **CoreFrameworkBundle** serves as the foundational pillar of the [UVDesk Community][1] helpdesk system. It encapsulates the essential functionalities and acts as a powerful integration framework that streamlines the inclusion of various community helpdesk packages. Designed with modularity and extensibility in mind, this standalone bundle ensures seamless interoperability across different components, empowering developers to enhance, customize, and scale the helpdesk system effortlessly. By providing a unified and robust core architecture, the CoreFrameworkBundle significantly accelerates development while maintaining stability, making it an indispensable asset at the heart of the UVDesk ecosystem.

The core framework bundle comes loaded with the following features:

  * **Ticket Query System** - Effortlessly convert customer inquiries into organized support tickets for streamlined issue tracking.

  * **Mailboxes** - Seamlessly integrate your support email mailboxes with the helpdesk to automatically convert every customer email into a trackable ticket.

  * **Email Templates** - Draft your frequent query responses to improve your productivity and minimize response time.

  * **User Management System** - Easily manage your support staff members into Admins, Groups & Teams with varying level of system privileges

Installation
--------------

This bundle can be easily integrated into any Symfony application (though it is recommended that you're using [Symfony 4][3], or your project has a dependency on [Symfony Flex][4], as things have changed drastically with the newer Symfony versions). Before continuing, make sure that you're using PHP 8 or higher and have [Composer][5] installed. You also need to ensure that you have the following PHP extensions installed:

  * [PHP IMAP][6]
  * [PHP Mailparse][7]

To require the core framework bundle into your symfony project, simply run the following from your project root:

```bash
$ composer require uvdesk/core-framework
```

License
--------------

The **CoreFrameworkBundle** and libraries included within the bundle are released under the [OSL-3.0 license][8]

[1]: https://www.uvdesk.com/
[2]: https://symfony.com/
[3]: https://symfony.com/4
[4]: https://flex.symfony.com/
[5]: https://getcomposer.org/
[6]: http://php.net/manual/en/book.imap.php
[7]: http://php.net/manual/en/book.mailparse.php
[8]: https://github.com/uvdesk/core-framework/blob/master/LICENSE.txt