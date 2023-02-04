Open Broadcaster (OBS) Remote Control Manager
=============================================

This project allows [Open Broadcaster (OBS)](https://obsproject.com/) scene selection to be remotely controlled via a standard web browser.  Now you can control the active scene in OBS from a phone, tablet, or other device anywhere in the world from any web browser without having to open any ports on a firewall.

![Open Broadcaster (OBS) Remote Control Manager](https://user-images.githubusercontent.com/1432111/216733991-2ffda342-4a30-4196-b19d-95516b9519b3.png)

[Open Broadcaster](https://obsproject.com/) is a very popular piece of software for recording and live streaming.  It has a powerful scene system which allows for multiple scenes to be setup and can be switched between with a single click.  But what if the computer running OBS to switch scenes is not on the same network, on a restrictive network such as a corporate environment or some public WiFi networks, or you want someone else to control the active scene remotely?  That's where this Remote Control Manager software comes in to enable remotely switching scenes in OBS.

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/) [![Discord](https://img.shields.io/discord/777282089980526602?label=chat&logo=discord)](https://cubiclesoft.com/product-support/github/)

Fetaures
--------

* Easy to use web browser interface for switching scenes.
* Responsive client software.  Scene switching is very fast with minimal lag.
* Client software works with the built-in OBS WebSocket plugin.
* Self-hosted server software for total control and peace of mind.
* Great for switching between multiple cameras during a livestream event.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

Either 'git clone' this repository or use the green "Code" button and "Download ZIP" above to obtain the latest release of this software.

This software is written in PHP and therefore depends on PHP being installed on the system.  Installing PHP can be a little tricky on Windows.  Here's a [portable version of PHP](https://github.com/cubiclesoft/portable-apache-maria-db-php-for-windows).  Other OSes have their own quirky method of installing PHP.  Consult your favorite search engine.

The ideal setup involves installing the self-hosted server on your own web host (e.g. a VPS).  However, there may be a number of public installs of the server software that you can freely use with the client software.  Public servers may not work as well as a privately hosted server.

Start OBS.  Go to Tools -> WebSocket Server Settings.  Enable the WebSocket server on the default port 4455, set a password, and then "Apply" the changes.

Start a Command Prompt or terminal, `cd` into the Remote Control Manager "client" directory and run:

```
php router.php -p YourOBSWebSocketPasswordHere https://grilledapps.com/obsmanager/ "Live Presentation"
```

If successful, OBS will now be connected to a public facing installation of the server software and will output a URL that looks like this:

```
Joined DRC channel 1 as client ID 2.
Channel name:  katun-tireh-letih
Users may join via:  https://grilledapps.com/obsmanager/?channel=katun-tireh-letih
```

Visit the URL on the device and web browser of your choice.  The web browser will list the scenes in OBS.  Simply select a scene to switch to it in OBS.

While anyone with the URL can switch scenes, the server software randomly generates a new, unique channel name every time the client software starts.

To stop the client software, press Ctrl + C in the Command Prompt or terminal.  Alternatively, just close OBS.

Server Setup
------------

The Getting Started section above uses a public Remote Control Manager server.  To setup your own self-hosted server, you will first need a server running Nginx, PHP FPM, PHP CLI, [PHP Data Relay Center (DRC)](https://github.com/cubiclesoft/php-drc), and the server software from this repository.

If that sounds like a lot, fortunately there's a faster way to get everything installed via [Server Instant Start](https://github.com/cubiclesoft/server-instant-start).  Server Instant Start can spin up a fully ready system on most VPS providers in a matter of minutes with everything installed and configured except this server software.

Note:  Purchasing a domain, setting up DNS, and enabling SSL/TLS certificates is left as an exercise.

Upload the contents of the "server" directory to a directory on your server.  Create a file called "config.php" in the same directory as "index.php" and apply corrected paths to PHP DRC on your system:

```php
<?php
	// Various configuration details to automate remote access.
	$drc_public_url = "wss://YourWebsiteDomain.com/drc/";
	$drc_public_origin = "http://127.0.0.1";

	$drc_private_url = "ws://127.0.0.1:7328";
	$drc_private_origin = "http://127.0.0.1";
```

Now point the client software at the newly configured private server installation.  If all goes well, the client will connect, join a DRC channel, and display the URL that users may join to control the active scene in OBS.
