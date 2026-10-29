### OMPCrossref Plugin

OMP 3.5

#### Introduction
This plugin registers DOIS for monographs and chapters for DOI provider [Crossref.org](https://crossref.org).

Current Schema version is [5.4.0](https://www.crossref.org/documentation/schema-library/metadata-deposit-schema-5-4-0/)

####  Installation
```bash
OMP=/path/to/OMP_INSTALLATION
cd $OMP/plugins/generic
git clone https://github.com/ajnyga/crossref-omp.git crossref

cd $OMP
php lib/pkp/tools/installPluginVersion.php plugins/generic/crossref/version.xml
```

####  Credits
Based on OMP Datacite Plugin and OJS Crossref Plugin by
[Erik Hanson](https://github.com/ewhanson)
[Dulip Withanage](https://github.com/withanage)  
[Christian Marsilius](https://github.com/nongenti)

[![Plugin created by The Federation of Finnish Learned Societies](https://img.shields.io/badge/Plugin%20created%20by-The%20Federation%20of%20Finnish%20Learned%20Societies-5A2A82?style=for-the-badge&labelColor=4B196E&logoColor=white)](https://tsv.fi/en/)