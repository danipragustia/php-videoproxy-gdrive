# PHP Video Proxy Google Drive
Simple Implementation to proxy video from Google Drive File

There no any update for this, unless important want like bug and essential features.

## How to use

Navigate `index.php?id=[YOUR_GOOGLE_DRIVE_FILE_ID]` and it will return hash code and resolution.

After that, navigate `index.php?id=[HASH_CODE]&stream=[RESOLUTION` for example `index.php?id=1963d03f0a2ffb95715ei2aba05cefaeaace8b2bcda91495b5672bade4d2&stream=720p`

## Installation
You need web server stack (PHP and Apache, nginx not support because using **Apache Environment Variables** ), to run this script.

For Windows you can use [XAMPP](apachefriends.org) or [Laragon](laragon.org)

PHP Extension Required :
- cURL

## License
MIT