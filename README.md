# FreshRSS - Fever API extension

This FreshRSS extension allows you to access FreshRSS with RSS readers that support the Fever API.

## Installation

To use it, upload the ```fever.php``` file to the FreshRSS location `/p/api/fever.php` on your server and enable API access in FreshRSS.

There is a major drawback when using this plugin, which is the username and password combination that you have to use.
You have to set the API password as md5 sum from the string 'username:password'.

So if you use **kevin** as username and **freshrss** as password you have to set your FreshRSS API password to **4a6911fb47a87a77f4de285f4fac856d**.

Thats because:
```bash
$ md5 -s "kevin:freshrss"
MD5 ("kevin:freshrss") = 4a6911fb47a87a77f4de285f4fac856d
```

In your favorite RSS reader you configure the **fever.php** as endpoint and still use **kevin** as username and **freshrss** as password. 

This limitation exists, because the Fever API was designed in way which is incompatible with the authentication system used by FreshRSS. 

You can use several online tools for calculating ypu API password if you don't have a md5 binary available.

## Features
Following features are implemented:

- fetching categories
- fetching feeds
- fetching RSS items (new, favorites, unread, by_id, by_feed, by_category, since)
- fetching favicons
- **hot** is not supported as there is nothing in FreshRSS that is similar

## Roadmap
Support will follow soon:

- setting read marker for item(s)
- setting starred marker for item(s)
- improve speed: currently there is some overhead while fetching entries, feeds and categories which can be eliminated

## About FreshRSS
[FreshRSS](https://freshrss.org/) is a great self-hosted RSS Reader written in PHP, which is can also be found here at [GitHub](https://github.com/FreshRSS/FreshRSS).

More extensions can be found at [FreshRSS/Extensions](https://github.com/FreshRSS/Extensions).

## Changelog

* Initial version

## Credits and license

This plugin was inspired by the [tinytinyrss-fever-plugin](https://github.com/dasmurphy/tinytinyrss-fever-plugin).
Thanks to @dasmurphy for sharing it!

The original plugin was released under GNU GPL version 2. I have no clue what that means for this plugin ... 