# Laravel Repository

## 📌 Sobre o Projeto
O **Laravel Repository** é um package para Laravel que abstrair a camada de dados, tornando nossa aplicação mais flexível para manutenção.


---

## 🚀 Instalação

### 1️⃣ Requisitos
Antes de instalar, certifique-se de que seu projeto atenda aos seguintes requisitos:
- PHP >= 8.0
- Laravel >= 10
- Composer instalado

### 2️⃣ Instalação do Package
Execute o seguinte comando no terminal:
```bash
  composer require risetechapps/repository-for-laravel
```

### 3️⃣ Publicar Configurações
```bash
  php artisan vendor:publish --provider="RiseTechApps\Repository\RepositoryServiceProvider"
```

### 4️⃣ Crie um Repository
```bash
  php artisan make:repository {name}
```

### 4️⃣ Verifique e configure o Model
```php
  class ClientEloquentRepository extends BaseRepository implements ClientRepository
  {
    public function entity(): string
    {
        return Client::class;
    }

    public function entityOn(): Client
    {
        return new Client();
    }
  }


  interface ClientRepository extends RepositoryInterface
  {
    public function entityOn();
  }
  
```
---

## 🛠 Contribuição
Sinta-se à vontade para contribuir! Basta seguir estes passos:
1. Faça um fork do repositório
2. Crie uma branch (`feature/nova-funcionalidade`)
3. Faça um commit das suas alterações
4. Envie um Pull Request

---

## 📜 Licença
Este projeto é distribuído sob a licença MIT. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.

---

💡 **Desenvolvido por [Rise Tech](https://risetech.com.br)**

