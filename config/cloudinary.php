<?php

/*
|--------------------------------------------------------------------------
| Cloudinary overrides
|--------------------------------------------------------------------------
|
| CloudinaryServiceProvider::register() merges the package's own config file
| into the "cloudinary" key, so only the values this application overrides
| need to be declared here — the credentials keep coming from CLOUDINARY_URL
| via the package defaults and are deliberately not repeated.
|
*/

return [

    /*
    | Seconds an upload request to Cloudinary may take before it is abandoned
    | and the calling controller falls back to the local public disk.
    |
    | The Cloudinary SDK's own default is 60 seconds (ApiConfig::DEFAULT_TIMEOUT).
    | Uploads here are synchronous and inside the request, so one unreachable
    | Cloudinary could hold a PHP worker — and every request queued behind it —
    | for a full minute. Ten seconds is comfortably above a healthy upload while
    | keeping a failure cheap.
    */
    'upload_timeout' => env('CLOUDINARY_UPLOAD_TIMEOUT', 10),

];
