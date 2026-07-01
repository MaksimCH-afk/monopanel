# ============================================================
#  Brand template — статический сайт на Apache.
#  Apache выбран намеренно: в корне есть рабочий .htaccess
#  (extensionless-URL и редиректы) — на Apache он применяется
#  как на проде, без переписывания правил под другой сервер.
# ============================================================
FROM httpd:2.4-alpine

# включаем mod_rewrite и разрешаем .htaccess (по умолчанию AllowOverride None)
RUN sed -i \
    -e 's/^#LoadModule rewrite_module/LoadModule rewrite_module/' \
    -e 's#AllowOverride None#AllowOverride All#g' \
    /usr/local/apache2/conf/httpd.conf

# сайт кладём в корень веб-сервера (что копировать — см. .dockerignore)
COPY . /usr/local/apache2/htdocs/

EXPOSE 80
# базовый образ сам запускает: httpd-foreground
