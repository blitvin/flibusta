<?php
// Help and legal information page

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$opdsPath = preg_replace('/\/help(\/|\/index\.html)?$/i', '/opds', $requestPath);
$opdsUrl = $host ? $scheme . '://' . $host . $opdsPath : '/opds';

$authUrlSample = $host ? $scheme . '://username:password@' . $host . $opdsPath : '/opds';

echo "<h4>Справка</h4><br><br>";

echo "<h5>OPDS</h5>";
echo "<p>OPDS (Open Publication Distribution System) — это стандартный каталогный протокол для электронных ридеров.
 Он позволяет подключаться к библиотеке как к каталогу, просматривать книги и загружать их из мобильного приложения.</p>";

echo "<p>Для подключения укажите URL OPDS-сервера вашей библиотеки <code>". htmlspecialchars($opdsUrl, ENT_QUOTES, 'UTF-8') . "</code>.</p>";

echo "<h6>Authentication / Аутентификация</h6>";
echo "<p>Некоторые ридеры поддерживают указание логина и пароля прямо в URL:</p>";
echo "<p><code>" . htmlspecialchars($authUrlSample, ENT_QUOTES, 'UTF-8') . "</code></p>";
echo "<p>Некоторые ридеры могут не корректно работать с параметрами аутентификации в URL. В таких ситуациях используйте URL без имени пользователя и пароля, а затем введите данные в появившемся диалоговом окне.</p>";

echo "<h6>Подключение в мобильных приложениях</h6>";
echo "<p>В приложениях типа FBReader, Moon+ Reader, Foliate, Aldiko и других выберите возможность добавления OPDS-источника и укажите URL, приведённый выше.</p> <hr style='width:50%; margin:auto;'/><br><br><br>";

echo "<h5>Восстановление доступа администратора</h5>";
echo "<p>Если вы забыли пароль администратора и не можете войти, сначала проверьте, не заблокирован ли аккаунт из-за слишком большого числа неудачных попыток. Блокировка действует только 15 минут, затем можно повторить попытку.</p>";
echo "<p>Новый административный аккаунт можно создать с помощью параметров переменной окружения <strong>FLIBUSTA_APP_ADMIN</strong> и пароля, заданного через переменную <strong>FLIBUSTA_APP_ADMIN_PASSWORD</strong> или секрет  <strong>FLIBUSTA_APP_ADMIN_PWD</strong>). 
    Использование Docker secrets для хранения пароля предпочтительнее, чем прямая передача его в окружении. После определения нового администратора в переменных docker-compose.yml, нужно перезапустить контейнер. Обратите внимание, нужно создать новый аккаунт.</p>";
echo "<p>После создания нового администратора войдите с его данными и удалите старый аккаунт, если он больше не нужен либо измените его пароль чтобы получить доступ.</p><hr style='width:50%; margin:auto;'/><br><br><br>";
echo "<h5>Юридическая информация / Legal notice</h5>";
echo "<h6>Лицензия / License</h6>";
echo "<p>Проект распространяется по лицензии <strong>GPL v2</strong>. Исходный код доступен на GitHub: <a href='https://github.com/blitvin/flibusta'>github.com/blitvin/flibusta</a>.</p>";
echo "<p>The project is distributed under the <strong>GPL v2</strong> license. The source code is available at <a href='https://github.com/blitvin/flibusta'>github.com/blitvin/flibusta</a>.</p>";

echo "<h6>Изображение / Background image</h6>";
echo "<p>Фоновое изображение на экране входа получено с сайта freepics.com по бесплатной лицензии. Изображение <a href='http://www.freepik.com'>Designed by macrovector / Freepik</a>.</p>";
echo "<p>The background image on the login screen is obtained from freepics.com under a free license. The image is <a href='http://www.freepik.com'>Designed by macrovector / Freepik</a>.</p>";

echo "<h6>Отказ от ответственности / Disclaimer</h6>";
echo "<p>Библиотека поставляется <strong>КАК ЕСТЬ</strong>. Авторы проекта не несут ответственности за любой ущерб, потерю данных или другие последствия, вызванные некорректной работой кода.</p>";
echo "<p>The library is supplied <strong>AS IS</strong>. The project authors claim no responsibility for any damage, loss, or other consequences resulting from the code not operating as expected.</p>";

echo "<p>Ответственность за эксплуатацию библиотеки полностью лежит на администраторах и читателях конкретного экземпляра библиотеки установленного на их оборудовании. Администраторы и пользователи библиотеки берут на себя полную ответственность за ее работу.</p>";
echo "<p>Operation of the library is solely the responsibility of the administrators and readers of the library instance. No liability may be attributed to the project authors; the administrators and users of the instance assume full responsibility for all aspects of the library operation.</p>";
