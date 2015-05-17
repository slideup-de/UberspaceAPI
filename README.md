PHP API für Uberspace

Beispiel

	$uberspace = new UberspaceAPI(USERNAME,PASSWORD);
	$uberspaceData = $uberspace->getData();

	print_r($uberspaceData);

	
Generiert ein Array, mit dem Benutzernamen, der zugeordnete Host, angebundene Web- und MailDomains, dem eingestellten Wunschpreis und dem Guthaben auf dem Account

	Array
	(
        [username]   =>   USERNAME
        [price]   =>   3.00 //wunschpreis
        [current_amount]   =>   2.00 //guthaben
        [host]   =>   HOST.uberspace.de
        [domains_web]   =>   Array
                (
                        [0]   =>   *.DOMAIN.TLD
                        [1]   =>   DOMAIN.TLD
                        [2]   =>   *.USERNAME.HOST.uberspace.de
                )

        [domains_mail]   =>   Array
                (
                        [0]   =>   DOMAIN.TLD
                )

	)