<?php
/*
 * Copyright 2016 Google LLC
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are
 * met:
 *
 *     * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 *     * Neither the name of Google Inc. nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
namespace Google\ApiCore\Tests\Unit;

use Google\ApiCore\ApiException;
use Google\ApiCore\ClientStream;
use Google\ApiCore\Testing\MockClientStreamingCall;
use Google\ApiCore\Testing\MockStatus;
use Google\Auth\Logging\StdOutLogger;
use Google\Rpc\Code;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

class ClientStreamTest extends TestCase
{
    use ProphecyTrait;
    use TestTrait;

    public function testNoWritesSuccess()
    {
        $response = 'response';
        $call = new MockClientStreamingCall($response);
        $stream = new ClientStream($call);

        $this->assertSame($call, $stream->getClientStreamingCall());
        $this->assertSame($response, $stream->readResponse());
        $this->assertSame([], $call->popReceivedCalls());
    }

    public function testNoWritesFailure()
    {
        $response = 'response';
        $call = new MockClientStreamingCall(
            $response,
            null,
            new MockStatus(Code::INTERNAL, 'no writes failure')
        );
        $stream = new ClientStream($call);

        $this->assertSame($call, $stream->getClientStreamingCall());
        $this->assertSame([], $call->popReceivedCalls());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('no writes failure');

        $stream->readResponse();
    }

    public function testManualWritesSuccess()
    {
        $requests = [
            $this->createStatus(Code::OK, 'request1'),
            $this->createStatus(Code::OK, 'request2')
        ];
        $response = $this->createStatus(Code::OK, 'response');
        $call = new MockClientStreamingCall($response->serializeToString(), ['\Google\Rpc\Status', 'mergeFromString']);
        $stream = new ClientStream($call);

        foreach ($requests as $request) {
            $stream->write($request);
        }

        $this->assertSame($call, $stream->getClientStreamingCall());
        $this->assertEquals($response, $stream->readResponse());
        $this->assertEquals($requests, $call->popReceivedCalls());
    }

    public function testManualWritesFailure()
    {
        $requests = [
            $this->createStatus(Code::OK, 'request1'),
            $this->createStatus(Code::OK, 'request2')
        ];
        $response = $this->createStatus(Code::OK, 'response');
        $call = new MockClientStreamingCall(
            $response->serializeToString(),
            ['\Google\Rpc\Status', 'mergeFromString'],
            new MockStatus(Code::INTERNAL, 'manual writes failure')
        );
        $stream = new ClientStream($call);

        foreach ($requests as $request) {
            $stream->write($request);
        }

        $this->assertSame($call, $stream->getClientStreamingCall());
        $this->assertEquals($requests, $call->popReceivedCalls());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('manual writes failure');

        $stream->readResponse();
    }

    public function testWriteAllSuccess()
    {
        $requests = [
            $this->createStatus(Code::OK, 'request1'),
            $this->createStatus(Code::OK, 'request2')
        ];
        $response = $this->createStatus(Code::OK, 'response');
        $call = new MockClientStreamingCall($response->serializeToString(), ['\Google\Rpc\Status', 'mergeFromString']);
        $stream = new ClientStream($call);

        $actualResponse = $stream->writeAllAndReadResponse($requests);

        $this->assertSame($call, $stream->getClientStreamingCall());
        $this->assertEquals($response, $actualResponse);
        $this->assertEquals($requests, $call->popReceivedCalls());
    }

    public function testWriteAllFailure()
    {
        $requests = [
            $this->createStatus(Code::OK, 'request1'),
            $this->createStatus(Code::OK, 'request2')
        ];
        $response = $this->createStatus(Code::OK, 'response');
        $call = new MockClientStreamingCall(
            $response->serializeToString(),
            ['\Google\Rpc\Status', 'mergeFromString'],
            new MockStatus(Code::INTERNAL, 'write all failure')
        );
        $stream = new ClientStream($call);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('write all failure');

        try {
            $stream->writeAllAndReadResponse($requests);
        } finally {
            $this->assertSame($call, $stream->getClientStreamingCall());
            $this->assertEquals($requests, $call->popReceivedCalls());
        }
    }

    public function testWriteCallsLogger()
    {
        $logger = $this->prophesize(StdOutLogger::class);
        $logger->debug(Argument::cetera())
            ->shouldBeCalledTimes(2);

        $requests = [
            $this->createStatus(Code::OK, 'request1'),
            $this->createStatus(Code::OK, 'request2')
        ];
        $response = $this->createStatus(Code::OK, 'response');
        $call = new MockClientStreamingCall($response->serializeToString(), ['\Google\Rpc\Status', 'mergeFromString']);
        $stream = new ClientStream($call, logger: $logger->reveal());

        $stream->write($requests[0]);
        $stream->write($requests[1]);
    }

    public function testReadCallsLogger()
    {
        $logger = $this->prophesize(StdOutLogger::class);
        $logger->debug(Argument::cetera())
            ->shouldBeCalledTimes(2);

        $requests = [
            $this->createStatus(Code::OK, 'request1'),
        ];
        $response = $this->createStatus(Code::OK, 'response');
        $call = new MockClientStreamingCall($response->serializeToString(), ['\Google\Rpc\Status', 'mergeFromString']);
        $stream = new ClientStream($call, logger: $logger->reveal());

        $stream->write($requests[0]);
        $stream->readResponse();
    }
}
