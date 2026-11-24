<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class StoreUploadRequest extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'file' => 'required|file|extensions:csv,txt|max:'.Config::integer('import.max_kb'),
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $errors = $validator->errors();

        throw new HttpResponseException(new JsonResponse([
            'message' => $errors->first(),
            'errors' => $errors->messages(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
