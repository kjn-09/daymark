<?php

return json_decode(
    file_get_contents(base_path('../shared/registration.json')),
    true,
    flags: JSON_THROW_ON_ERROR,
);
