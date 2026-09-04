#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh
. "$project_dir/scripts/lib-env.sh"
kuka_load_env_file "$project_dir/.env"

docker compose run --rm wp-cli wp eval-file /project-scripts/seed-attributes.php
docker compose run --rm wp-cli wp eval-file /project-scripts/seed.php
docker compose run --rm wp-cli wp eval-file /project-scripts/migrate-sizes.php
docker compose run --rm wp-cli wp eval-file /project-scripts/seed-content.php

printf '%s' "$WP_MANAGER_PASSWORD" | docker compose run --rm -T -e KUKA_MANAGER_USER="$WP_MANAGER_USER" wp-cli wp eval '
  $password = stream_get_contents( STDIN );
  $login = (string) getenv( "KUKA_MANAGER_USER" );
  if ( "" === $password || "" === $login ) { throw new RuntimeException( "Manager credential setup failed." ); }
  $user = get_user_by( "login", $login );
  if ( ! $user ) {
    $user_id = wp_create_user( $login, $password, "manager@kukaisland.test" );
    if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
    $user = get_user_by( "id", $user_id );
  }
  $user->set_role( "shop_manager" );
  wp_set_password( $password, $user->ID );
'
