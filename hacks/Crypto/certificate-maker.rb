require 'openssl'

pkey_data = File.read("id_rsa")
pkey_password = ""
subject = "/O=Honest Achmed's Used Cars and Certificates/OU=Self-made/CN=Geremia's cert"
expiry_years = 1

# Fill basic v1 fields
cert = OpenSSL::X509::Certificate.new
cert.version = 2  # for X.509 v3
cert.serial = 1
cert.subject = OpenSSL::X509::Name.parse(subject)
cert.issuer = cert.subject
cert.not_before = Time.now
cert.not_after = Time.now + (expiry_years * 365 * 86400)

# Add v3 extensions
extf = OpenSSL::X509::ExtensionFactory.new
extf.subject_certificate = cert
extf.issuer_certificate = cert
cert.extensions = [
	# basicConstraints is marked as 'critical' here
	extf.create_extension("basicConstraints", "CA:FALSE", true),
	extf.create_extension("subjectKeyIdentifier", "hash"),
	# 'copy' means needed information will be taken from subjectKeyIdentifier
	extf.create_extension("authorityKeyIdentifier",
		"keyid:copy, issuer:copy"),
	extf.create_extension("keyUsage",
		"digitalSignature, keyEncipherment, nonRepudiation"),
	extf.create_extension("extendedKeyUsage",
		"clientAuth, serverAuth, emailProtection"),
]

# Add public key and self-sign
pkey = OpenSSL::PKey::RSA.new(pkey_data, pkey_password)
cert.public_key = pkey
cert.sign(pkey, OpenSSL::Digest::SHA1.new)

puts cert.to_pem
