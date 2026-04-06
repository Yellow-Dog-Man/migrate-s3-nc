# migrate-s3-nc

It's a tool to migrate a structured database to S3 storage.

# Requirement

First, you must get this project with git or download it in zip format.

```bash
git clone https://github.com/arawa/migrate-s3-nc.git
```

If you have not composer, follow this instruction page [to install composer](https://getcomposer.org/download/) . 

It's very important to build this project.

```bash
cd migrate-s3-nc
composer install
```

## Step 1 : Disabled your web service

You **must** stop the web service to ensure that no one or a technical user use Nextcloud.

```bash
# nginx
sudo systemctl stop nginx.service
# apache (Debian based)
sudo systemctl stop apache.service
# or this
sudo systemctl stop apache2.service
# apache (CentOS based)
sudo systemctl stop httpd.service
```

⚠️ **NOTE** : If you have a web cluster. You must enter this command on the others servers in the cluster.

## Step 2 : Check your files & database

Use the `files_scan.sh` script from the project folder to scan the filesystem of Nextcloud :

```bash
# script to scan your filesystem of nextcloud
./files_scan.sh /var/www/html/nextcloud <web-user> 
```

⚠️ **NOTE** : The `<web-user>` is the user of your web server : `nginx` for the nginx, `www-data` for the apache CentOS based, `apache` or `apache2` for Debian based .

Go to Nextcloud folder, example : `/var/www/html/nextcloud`, to use occ command line and cleanup the database of nextcloud.

```bash
# cleanup your database
sudo -u <web-user> php occ files:cleanup
```

⚠️ **NOTE** : I think you need to enter the commands for this step on the others servers in the web cluster if you have one.

## Step 3 : Comment your cron tasks for Nextcloud

```bash
sudo crontab -u <web-user> -e
```

⚠️ **NOTE** : I think you need to enter this command for this step on the others servers in the web cluster if you have one.

## Step 4 : Enable the Nextxcloud's maintenance

Enable the **maintenance** mode of Nextcloud. This mode prevent the technical user or users to access your Nextcloud's instance.

```bash
cd /var/www/html/nextcloud
sudo -u <web-user> php occ maintenance:mode --on
```

This step is **very important**. Otherwise, your Nextcloud will recreate the storages in the `oc_storages` database table.

⚠️ **NOTE** : You must enter this command on the others servers in the web cluster if you have one.

## Step 5 : backup your database

Run this command line to backup your database :

```bash
mysqldump --user <user-database> --password <database-name> > <database-name>-backup.sql
```

And particularly the `oc_storages` databalse table :

```bash
mysqldump --user <user-database> --password <database-name> oc_storages > <database-name>-oc_storages-backup.sql
```

If you have a problem with the migration, **you can just rollback this database table** and not the entire database.

Now, you can configure your `.env` file.

⚠️ **NOTE** : I think for this step you don't need to enter these commands on all the servers in your web cluster if you have one.

## Configure `.env` file

You must copy the `.env-sample` to `.env` file and define your config in `.env` file.

Here is the content of the .env file.

```ini
# Nextcloud Informations
NEXTCLOUD_FOLDER_PATH=""
NEXTCLOUD_CONFIG_PATH="/config/config.php"

# MYSQL Informations
MYSQL_DATABASE_SCHEMA=""
MYSQL_DATABASE_USER=""
MYSQL_DATABASE_PASSWORD=""
MYSQL_DATABASE_HOST=""

#########################################
#                                       #
# You should choice between "S3 Swift"  #
# and "S3 Anothers Informations" !      #
#                                       #
# Not both !                            #
#                                       #
#########################################

# Required
S3_PROVIDER_NAME=""
S3_ENDPOINT=""
S3_REGION=""
S3_KEY=""
S3_SECRET=""
S3_BUCKET_NAME=""

# S3 SWift Informations (Example: OVH, OpenStack)
S3_SWIFT_URL=""
S3_SWIFT_USERNAME=""
S3_SWIFT_PASSWORD=""
S3_SWIFT_ID_PROJECT=""

# S3 Anothers Informations
S3_HOSTNAME=""
S3_PORT=""

```

Nextcloud informations :

- `NEXTCLOUD_FOLDER_PATH` : Is the data folder where the files are stored
- `NEXTCLOUD_CONFIG_PATH` : Is the config file of Nextcloud. It's the only file to not modif

MySQL informations :

- `MYSQL_DATABASE_SCHEMA` : The database name
- `MYSQL_DATABASE_USER` : The applicative user to connect to database
- `MYSQL_DATABASE_PASSWORD` : The password of your applicative user
- `MYSQL_DATABASE_HOST` : The hostname of your database (only `local` works)

S3 Informations :

These env vars are required

- `S3_PROVIDER_NAME` : The provider name. For example : `OVH`, `SWIFT`, `Scaleway`, `AWS`, `Dell`, and so on.
- `S3_ENDPOINT` : The URL of your object storage server
- `S3_REGION` : The region of you object storage server
- `S3_KEY` : The key to use object storage server's API
- `S3_SECRET` : The secret which is with the `S3_KEY`
- `S3_BUCKET_NAME` : The bucket name in your S3 server

These env vars are required if you use SWIFT (`ovh`, `swift`, `openstack`)

- `S3_SWIFT_URL` : 
- `S3_SWIFT_USERNAME` :
- `S3_SWIFT_PASSWORD` :
- `S3_SWIFT_ID_PROJECT` :

### Configuration examples

If you use a swift S3 (OVH, OpenStack, Swift and so on. ) :

```ini
# Nextcloud Informations
NEXTCLOUD_FOLDER_PATH="/var/www/html/my-nextcloud"
NEXTCLOUD_CONFIG_PATH="/config/config.php"

# MYSQL Information
MYSQL_DATABASE_SCHEMA="nextcloud"
MYSQL_DATABASE_USER="nextcloud"
MYSQL_DATABASE_PASSWORD="123456789"
MYSQL_DATABASE_HOST="localhost"

#########################################
#                                       #
# You should choice between "S3 Swift"  #
# and "S3 Anothers Informations" !      #
#                                       #
# Not both !                            #
#                                       #
#########################################

# Required
S3_PROVIDER_NAME="OVH"
S3_ENDPOINT="https://s3.par.cloud.ovh.net"
S3_KEY="dj9dalxnxxdock1xnij4k5tl0aent22v"
S3_SECRET="gtqkfradr7905d7sl6jxkwm2i0ll5ankg"
S3_BUCKET_NAME="my-bucket"
S3_REGION="par"

# S3 SWift Informations (Example: OVH, OpenStack)
S3_SWIFT_URL="https://auth.cloud.ovh.net/v3"
S3_SWIFT_USERNAME="my-user"
S3_SWIFT_PASSWORD="Nq2jjNpTfQFiLMYUCGW8NcNvtf3nHVe2"
S3_SWIFT_ID_PROJECT="5121915117413912"

# S3 Anothers Informations
S3_HOSTNAME=""
S3_PORT=""
```

If you use a S3 compatible (Scaleway, Dell, AWS S3, Minio and so on.) :

```ini
# Nextcloud Informations
NEXTCLOUD_FOLDER_PATH="/var/www/html/nextcloud"
NEXTCLOUD_CONFIG_PATH="/config/config.php"

# MYSQL Information
MYSQL_DATABASE_SCHEMA="nextcloud"
MYSQL_DATABASE_USER="nextcloud"
MYSQL_DATABASE_PASSWORD="sLK15Aa875/0!sqd"
MYSQL_DATABASE_HOST="localhost"

#########################################
#                                       #
# You should choice between "S3 Swift"  #
# and "S3 Anothers Informations" !      #
#                                       #
# Not both !                            #
#                                       #
#########################################

# Required
S3_PROVIDER_NAME="Scaleway"
S3_ENDPOINT="https://s3.fr-par.scw.cloud"
S3_KEY="SD130KQ19SL01L0"
S3_SECRET="ca97csd8-0t80-asf1-8c2a-ba69662az3e0"
S3_BUCKET_NAME="my-bucket"
S3_REGION="fr-par"

# S3 SWift Informations (Example: OVH, OpenStack)
S3_SWIFT_URL=""
# S3_ENDPOINT="https://storage.sbg.cloud.ovh.net/v1/AUTH_177b256fb8674b9c9880b575191b9709"
S3_SWIFT_USERNAME=""
S3_SWIFT_PASSWORD=""
S3_SWIFT_ID_PROJECT=""

# S3 Anothers Informations
S3_HOSTNAME="s3.fr-par.scw.cloud"
S3_PORT="443"
```


After all these, you can go in the `How to run ?` part !

# <a name="how-to-run"/> How to run ?

First, you must build with composer command-line : `composer install` from the project folder. 

# Available Options
```bash
# Show help
$ php main.php --help

# Run all steps (default - original behavior)
$ php main.php
# or
$ php main.php --all

# Upload files to S3 only (prepare S3 location ahead of time)
$ php main.php --upload

# Update database (oc_storages table) only
$ php main.php --sql

# Generate Nextcloud config file only
$ php main.php --config
```

### Recommended Workflow for Large Migrations

For large installations, it's recommended to separate the file upload from the database update:

```bash
# Step 1: Upload all files to S3 (this may take hours or days)
$ php main.php --upload

# Step 2: Verify files are in S3 (check your bucket)
# ... verification steps ...

# Step 3: Update the database (this is fast - minutes)
$ php main.php --sql

# Step 4: Generate config (included in --sql, but can run separately)
$ php main.php --config
```

**Benefits of this approach:**
- **Reduced Risk**: File uploads can take a long time and may fail. By separating them, you can retry uploads without touching the database.
- **Easy Rollback**: If something goes wrong, you can easily restore the database from backup without worrying about partially uploaded files.
- **Verification**: You can verify all files are in S3 before making any database changes.

⚠️ Be careful : The migration could take hours or days. I advise you to use the [byobu](https://www.byobu.org/) app to have a virtual session and exit to leave your terminal whenever you want.
Here is a [cheat sheet](https://gist.github.com/devhero/7b9a7281db0ac4ba683f) to navigate in byobu.

⚠️ **NOTE** : You must enter this command on one of the servers in your web cluster if you have one.

After its execution, it prints this message :

```
✓ Migration steps completed successfully!

Next steps:
  1. Review the generated new_config.php file
  2. Backup your current Nextcloud config.php
  3. Replace config.php with new_config.php
  4. Restart your web server
```

It means the script has run with success !

You must check the good config in the `new_config.php` file before replace it with the Nextcloud's `config.php` file.

⚠️ **NOTE** : I think you need to move the `new_config.php` to `config.php` on all the servers in your web cluster if you have one.

Once the copy is done. You can check in your `oc_storages` database table if it's correct :

```sql
MariaDB [nextcloud]> select * from oc_storages where id like "%home%";
Empty set (0.000 sec)

```


Then, you must :

1. Decomment cron tasks
2. Disable Nextcloud's maintenance
3. Enable your web server (nginx, apache and so on)


# Dependencies

- Dotenv : To set your config.
- Aws SDK Php : To push your files.

# How to purge the bucket ?

You can purge your bucket with the purgeBucket.php file.
It's very useful when your migration has been stopped abruptly.

```bash
php purgeBucket.php
```

🚨 Caution : This program deletes all objects in your bucket and you must rollback your database, in particular the `oc_storage` database table. Then, begin the migration from [How to run ?](#how-to-run) part.

⚠️ **NOTE** : I think you can run this command on one of the servers in your web cluster if you have one.


# Credits

The initial code of this software has been developed by [Arawa](https://www.arawa.fr/) with the financial support of [Sorbonne Université](https://www.sorbonne-universite.fr/).

<div align="center" width="100%">
    <div width="60%;">
        <img src="./images/Logo_SU_horiz_RVB_color.svg" width="20%">
        <img src="./images/logo_arawa.svg" width="30%">
    </div>
</div>
