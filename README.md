# youtube-dl-win-gui
Windows (7,8,10,11) GUI of the [yt-dlp](https://github.com/yt-dlp/yt-dlp/) media downloader.

## Screenshot
![youtube-dl-win-gui main window](https://raw.githubusercontent.com/rogit/youtube-dl-win-gui/screenshots/builds/screenshot.png)

## Installation

1. Download & extract the .7z archive from [Releases page](https://github.com/rogit/youtube-dl-win-gui/releases)
2. Run youtube-dl-win-gui.exe

Note: Some [anti-viruses complain about this file](https://www.virustotal.com/gui/file/7281c8a0beab47dcdbc49f2ff91a7f20bc941f4430064abc057dce572ffdc4ae). Do not believe them or [compile it yourself](https://github.com/rogit/windows-desktop-web-app).

## Requirements

* It's more convenient to use Firefox because of YouTube's cookie issues.

    Option `--cookies-from-browser firefox`


* Read [https://github.com/yt-dlp/yt-dlp/wiki/EJS](https://github.com/yt-dlp/yt-dlp/wiki/EJS). JS runtime is not included to archive. 

    Option `--js-runtimes node`