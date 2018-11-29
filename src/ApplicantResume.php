<?php

namespace GoVa\Scripts\Src;

use Aws\S3\S3Client;
use Dotenv\Dotenv;
use Google_Client;
use Google_Service_Drive;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;

class ApplicantResume
{
    protected $s3Client;
    protected $liteDb;
    protected $mailer;
    protected $google;

    public function __construct()
    {
        $dotEnv = new Dotenv(__DIR__ . './../');
        $dotEnv->load();
        $this->s3Client = $this->_connectToS3();
        $this->liteDb = new PDO(sprintf('sqlite:%s/../db/r24-prod.db', __DIR__));
        $this->mailer = new PHPMailer(true);
        $this->google = $this->_connectToGoogle();
    }

    public function fire()
    {
        try {
            $exempted = $this->_exemptedApplicants();
            $applicants = $this->_getApplicants($exempted);
            if($applicants !== false){
                foreach ($applicants as $row) {
                    if($row['resume_file'] > ''){
                        $new_file = $this->_getFile($row);
                        $row['local_file'] = $new_file;
                        $this->_saveToExempted($row);
                    }
                }
                return true;
            }
        } catch (\Exception $e) {
            print($e->getMessage() . PHP_EOL);
            return false;
        }
    }


    private function _getApplicants($exempted)
    {
        try {
            $host = getenv('R24_DB_HOST');
            $name = getenv('R24_DB_NAME');
            $username = getenv('R24_DB_USER');
            $password =  getenv('R24_DB_PASSWORD');
            $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;', $host, $name), $username, $password);
            $query = "SELECT app.user_id, app.resume_file, pi.given_name, pi.additional_name, pi.family_name FROM applications as app
                                                  LEFT JOIN personal_info as pi ON pi.user_id = app.user_id
                                                  where app.resume_file > '' ";
            if (count($exempted) > 0) {
                $query .= " AND app.user_id NOT IN ( " . implode(", ", $exempted) . " )";
            }
            $statement = $pdo->query($query);
            $applicants = [];
            while($row = $statement->fetch(PDO::FETCH_ASSOC)){
                $applicants[] = $row;
            }
            $pdo = null;

            return $applicants;
        } catch (\Exception $e) {
            print $e->getMessage().PHP_EOL;

            return false;
        }
    }

    private function _exemptedApplicants()
    {
        try {
            $statement = $this->liteDb->query('SELECT * FROM applicants');

            $exempted = [];
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)){
                $exempted[] = $row['id'];
            }
            return $exempted;
        } catch (\Exception $e) {
            echo $e->getMessage().PHP_EOL;
            return [];
        }
    }

    public function _getFile($applicant)
    {
        try {
            $file_split = explode('.', $applicant['resume_file']);
            $extension = end($file_split);
            $local = sprintf('%s/../tmp/%s %s %s.%s', __DIR__, $applicant['given_name'], $applicant['additional_name'], $applicant['family_name'], $extension);
            print sprintf('Saving %s to %s ... ', $applicant['resume_file'], $local);
            $this->s3Client->getObject([
                'Bucket' => 'r24-prod.resourcefull.cc',
                'Key' => $applicant['resume_file'],
                'SaveAs' => $local
            ]);
            print 'Saved.'.PHP_EOL;
            return $local;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function _saveToExempted($applicant)
    {
        try {
            print 'Saving to exempted...';
            $sql = "INSERT INTO applicants (id, path, sent) VALUES(?,?,?)";
            $path = str_replace(__DIR__, '', $applicant['local_file']);
            $this->liteDb->prepare($sql)->execute([$applicant['user_id'], $path, false]);
            print 'Saved.'.PHP_EOL;
            return true;
        } catch (\Exception $e) {
            print $e->getMessage().PHP_EOL;
            return false;
        }
    }

    public function sendMail()
    {

        try {
            $this->mailer->SMTPDebug = 2;
            $this->mailer->isSMTP();
            $this->mailer->Host = getenv('MAIL_HOST');
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = getenv('MAIL_USERNAME');
            $this->mailer->Password = getenv('MAIL_PASSWORD');
            $this->mailer->SMTPSecure = 'tls';
            $this->mailer->Port = getenv('MAIL_PORT');

            $this->mailer->setFrom('alex.culango@go-va.com.au', 'Mailer');
            $this->mailer->addAddress('alex.culango@gmail.com', 'Alex Culango');
            $this->mailer->addReplyTo('alex.culango@go-va.com.au', 'Mailer');

            //Attachments
            $files = glob(__DIR__.'/../tmp/*');
            foreach ($files as $file){
                $this->mailer->addAttachment($file);
            }

            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Resumes of the new applicants';
            $this->mailer->Body = 'The attached files are the resumes of the new applicants';
            $this->mailer->send();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }


    private function _getPDOConnection($host, $port, $name, $username, $password)
    {
        try {
            return new PDO(sprintf('mysql:host=%s;dbname=%s;port=%d;', $name, $host, $port), $username, $password);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function _connectToS3()
    {
        $params = [
            'region' => getenv('AWS_DEFAULT_REGION'),
            'version' => '2006-03-01',
            //'endpoint' => getenv('AWS_URL'),
            'credentials' => [
                'key' => getenv('AWS_ACCESS_KEY_ID'),
                'secret' => getenv('AWS_SECRET_ACCESS_KEY')
            ]
        ];

        return new S3Client($params);
    }


    private function _connectToGoogle(){
        $client = new Google_Client();
        $client->setApplicationName('GoVa Scripts');
        $client->setScopes(Google_Service_Drive::DRIVE_FILE);
        $client->setAuthConfig(__DIR__.'/../config/google.json');
        return new Google_Service_Drive($client);
    }

    public function drive(){
        $results = $this->google->files->listFiles();

        if (count($results->getFiles()) == 0) {
            print "No files found.\n";
        } else {
            print "Files:\n";
            foreach ($results->getFiles() as $file) {
                printf("%s (%s)\n", $file->getName(), $file->getId());
            }
        }
    }

}