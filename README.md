# PushApi

## Index

- [Introduction](#introduction)
  - [How it works](#how-it-works)
  - [Targets](#targets)
    - [Email](#email)
    - [Smartphones](#smartphones)
    - [Twitter](#twitter)
  - [Schemes](#schemes)
    - [General view](#general-view)
    - [DataBase](#database)
- [Tools used](#tools-used)
- [Comments](#comments)
- [Support](#support)
- [Pending](#pending)

## Introduction

The PushApi is a server side project using PHP. It provides a way to notify users of different kind of events. There is the possibility to send notifications using unicast (target user), multicast (interested group) or broadcast (all users).

> This is a huge project that it is being implemented during the final degree project. Once finished, it will be able to accept external contributions.

### How it works

The API has an internal database (the tables will be described in the database scheme [DataBase](#database)).
In order to receive events, users must be registered into the API and then they can be subscribed into different Themes (this themes will be set by the administrator of the API). When user subscribes into a new Theme, user can choose where he wants to receive the notification (mail, smartphone, all, ...), by default, notifications will be sent via all the devices in order to force him to set its preferences.
The multicast Themes are assigned to different Channels that users can also subscribe.

When a notification is sent, API always returns the result directly to the client but it will send the notification when it can. For each target it has a Redis queue that sends step by step the different notifications that are being added continually to the various queues (soon it will be added [Forever](http://github.com/nodejitsu/forever) in order to ensure that a given script runs continuously).

### Targets

The API is being developed in order to support all kinds of targets if all these targets are configured correctly but the initial expected targets that is wanted to reach before the end of this project are the following ones:

#### Email

The basic notification method it is done via email (sometimes is called as SPAM due to its bad use). This API will send all mails to subscribed users without using external mailing services.


#### Smartphones

The other targets of this project are the most used smartphones (mainly Android and iOs) using the official servers for each company:
- GCM ([Google Cloud Messaging](https://developer.android.com/google/gcm/index.html)).
- APNS ([Apple Push Notification Service](https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/ApplePushService.html)).

Both servers let sending notifications to various users with only one message. That is an advantage against the mail service.
At the beginning it was proposed to send notifications directly to the different smartphones without using the official services but the idea was deprecated because the lack of time and experience were a fisic solid wall.

#### Twitter

This is a new target that has been proposed during the project but it won't be applied until the main targets are finished. The purpose of this target is to make a Twitter tweet mentioning the target users interested on receive the notifications.

[Back to index](#index)

### Schemes

The following schemes wants to be descriptive parts of the project in order to make it easier to understand how it works or what is its functionality.

#### General view

This is a possible scheme of what the project wants to be:

![pushApi](img/option3.png)

#### DataBase

The current MySQL tables used are the following ones:

![pushApi](img/db_design.png)

It is not represented in any scheme but there are 3 Redis Lists used in order to queue the notifications before send them properly.

[Back to index](#index)

## Tools used
- Server Apache2
- MySQL
- Redis
- PHP 5.5+ (PHP 5.5 recommended)

[Back to index](#index)

## Comments

> It doesn't want to be the best notification system because I haven't got too much experience and the main target is to learn as much as I can, but I am trying to do something that I think that can improve my programing skills. As it says the beginning of the description, this is a degree project and it isn't expected to be the best system (but I am doing all my best).

[Back to index](#index)

## Support

If you want to give your opinion, you can send me an email or comment the project directly (if you want to contribute with information or resources). Once the official degree project finished, I will accept foreign contributions if you are interested in.

[Back to index](#index)

## Pending

Here are some pending tasks to do that aren't developed yet.

- Update the Android worker checking the GCM responses and update user data if it is required (some special cases required).
- Use [Forever](http://github.com/nodejitsu/forever) with the workers.
- Develop tests (it is one of the most important things while programing and I haven't got time to develop it yet).
- To log most of the functionalities.
- Create a Client that uses the API.
- Create some kind of mail template in order to send a better email.

Thank you.