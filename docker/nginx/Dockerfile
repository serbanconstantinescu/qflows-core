# qflows's web container: nginx with the app's public_html (the static files; the .php ones go to app:9000, the same path there), the site from
# templates/ (the image's envsubst: NGINX_SERVER_NAME)
#CLD: 2026-11-16T22:30:00+02:00: public_html from the app's image (compose.yaml: additional_contexts app = service:app, the same commit); the build context is docker/
FROM nginx:stable
COPY --from=app /srv/qflows/public_html /srv/qflows/public_html
COPY nginx/locations.conf /etc/nginx/nfn/locations.conf
COPY nginx/http.conf.template /etc/nginx/templates/default.conf.template
RUN rm -f /etc/nginx/conf.d/default.conf
