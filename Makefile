install:
	composer install

get:
	php artisan command:get-kakaku

clear:
	rm -f storage/app/price.json
	rm -f storage/logs/laravel.log
