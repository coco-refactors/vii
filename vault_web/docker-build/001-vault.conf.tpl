### VAuLT VHosts ###
#
# Rendered to /etc/apache2/sites-enabled/001-vault.conf by docker-entrypoint.sh,
# which substitutes the two port placeholders. One vhost per port, so no
# ServerName matching is needed. TLS is terminated upstream by the platform.

### Backend authoring/admin UI ###
<VirtualHost *:__BACKEND_PORT__>
	DocumentRoot "/var/www/html/backend/web/"

	<Directory "/var/www/html/backend/web/">
		# use mod_rewrite for pretty URL support
		RewriteEngine on
		# If a directory or a file exists, use the request directly
		RewriteCond %{REQUEST_FILENAME} !-f
		RewriteCond %{REQUEST_FILENAME} !-d
		# Otherwise forward the request to index.php
		RewriteRule . index.php

		# use index.php as index file
		DirectoryIndex index.php

		Require all granted
	</Directory>

	Alias /common /var/www/html/common/web/
	<Directory "/var/www/html/common/web/">
		# Deny all files by default
		Require all denied

		# Allow only specific file types
		<FilesMatch "(?i)\.(jpg|jpeg|png|gif|mp3|mp4|mpeg4|mov|m4v|m4a|css|js|html|htm|xml|json|txt|ico|svg|woff|woff2|ttf|eot|webp|webm|avif)$">
			Require all granted
		</FilesMatch>
	</Directory>
</VirtualHost>

### Frontend JSON API consumed by the iOS app ###
<VirtualHost *:__FRONTEND_PORT__>
	DocumentRoot "/var/www/html/frontend/web/"

	<Directory "/var/www/html/frontend/web/">
		# use mod_rewrite for pretty URL support
		RewriteEngine on
		# If a directory or a file exists, use the request directly
		RewriteCond %{REQUEST_FILENAME} !-f
		RewriteCond %{REQUEST_FILENAME} !-d
		# Otherwise forward the request to index.php
		RewriteRule . index.php

		# use index.php as index file
		DirectoryIndex index.php

		Require all granted
	</Directory>

	Alias /common /var/www/html/common/web/
	<Directory "/var/www/html/common/web/">
		# Deny all files by default
		Require all denied

		# Allow only specific file types
		<FilesMatch "(?i)\.(jpg|jpeg|png|gif|mp3|mp4|mpeg4|mov|m4v|m4a|css|js|html|htm|xml|json|txt|ico|svg|woff|woff2|ttf|eot|webp|webm|avif)$">
			Require all granted
		</FilesMatch>
	</Directory>
</VirtualHost>

# Hostname is supplied by the proxy; this only suppresses Apache's startup warning.
ServerName localhost

# Security
<Directory "/var/www/html">
	AllowOverride None
</Directory>

<FilesMatch "^\.">
	Require all denied
</FilesMatch>

<DirectoryMatch "/\.(git|svn|hg)/">
	Require all denied
</DirectoryMatch>

<FilesMatch "\.(htaccess|htpasswd|ini|log|sh|sql|conf)$">
	Require all denied
</FilesMatch>
